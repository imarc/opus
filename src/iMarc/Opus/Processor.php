<?php
namespace iMarc\Opus;

use Diff;
use Diff_Renderer_Text_Unified;
use Composer\Composer;
use Composer\IO\IOInterface;
use Composer\Installer\LibraryInstaller;
use Composer\Repository\InstalledRepositoryInterface;
use Composer\Package\PackageInterface;

/**
 * Composer processor
 *
 * @copyright iMarc, LLC
 * @author Matthew J. Sahagian [mjs] <matt@imarc.net>
 * @license MIT (See the LICENSE file at the root of this distribution)
 */
class Processor extends LibraryInstaller
{
	const PACKAGE_TYPE = 'opus-package';
	const NAME         = 'opus';
	const TAB          = '    ';

	/**
	 * The working installation map - tracks file copies
	 *
	 * @access private
	 * @var array
	 */
	private $installationMap = array();


	/**
	 * Whether or not opus support is enabled
	 *
	 * @access private
	 * @var boolean
	 */
	private $enabled = FALSE;


	/**
	 * The framework as defined by our extra options
	 *
	 * @access private
	 * @var string
	 */
	private $framework = NULL;


	/**
	 * The map file we read/write from/to
	 *
	 * @access private
	 * @var string
	 */
	private $mapFile = NULL;


	/**
	 * Get the difference between two files
	 *
	 * @static
	 * @access private
	 * @param string $a The path to the existing file
	 * @param string $b The path to the new (replacement) file
	 * @return string The unified diff between the two
	 */
	static private function diff($a, $b)
	{
		$a_code = explode("\n", file_get_contents($a));
		$b_code = explode("\n", file_get_contents($b));
		$diff   = new Diff($b_code, $a_code, array(
			'ignoreWhitespace' => TRUE,
			'ignoreNewLines'   => TRUE
		));

		return $diff->render(new Diff_Renderer_Text_Unified());
	}


	/**
	 * Initializes library installer.
	 *
	 * @param IOInterface $io The input/output handler
	 * @param Composer $composer The composer instance being run
	 * @param string $type The type
	 */
	 public function __construct(IOInterface $io, Composer $composer, $type = 'library')
	 {
		parent::__construct($io, $composer, $type);

		//
		// We can now parse our 'extra' configuration key for all our related information.
		//

		$extra           = $this->composer->getPackage()->getExtra();
		$this->mapFile   = getcwd() . DIRECTORY_SEPARATOR . self::NAME . '.map';
		$this->framework = $composer->getPackage()->getName();

		if (isset($extra[self::NAME]['options'])) {
			$options               = $extra[self::NAME]['options'];
			$this->externalMapping = isset($options['external-mapping'])
				? (bool) $options['external-mapping']
				: FALSE;

			if (isset($options['framework'])) {
				$this->framework = $options['framework'];

				//
				// Previous versions were noted as enabled if they set a framework.  This will
				// re-establish that, but will get overloaded later if enabled is explicitly set.
				//

				$this->enabled = TRUE;
			}
		}

		if (isset($extra[self::NAME]['enabled'])) {
			$this->enabled = $extra[self::NAME]['enabled'];
		}
	}


	/**
	 * Installs a package
	 *
	 * @access public
	 * @param InstalledRepositoryInterface $repo The repository for installed packages
	 * @param PackageInterface $package The package to install
	 * @return void
	 */
	public function install(InstalledRepositoryInterface $repo, PackageInterface $package)
	{
		parent::install($repo, $package);

		//
		// Return immediately if we have no information relevant to our installer
		//

		if (!$this->checkFrameworkSupport($package)) {
			return;
		}

		$this->loadInstallationMap();

		$this->copyMap(
			$this->buildPackageMap($package),
			$conflicts
		);

		if (count($conflicts)) {
			$this->resolve($conflicts);
		}

		$this->saveInstallationMap();
		$this->io->write(PHP_EOL);
	}


	/**
	 * Determine's whether or not we support the package
	 *
	 * @access public
	 * @param string $package_type The package type
	 * @return boolean TRUE if we support the package, FALSE otherwise
	 */
	public function supports($package_type)
	{
		return $package_type == self::PACKAGE_TYPE;
	}


	/**
	 * Updates a package (removing unused files, adding new ones in)
	 *
	 * @access public
	 * @param InstalledRepositoryInterface $repo The repository for installed packages
	 * @param PackageInterface $initial The initial package (originally installed)
	 * @param PackageInterface $target The target package (the new one)
	 * @return void
	 */
	public function update(InstalledRepositoryInterface $repo, PackageInterface $initial, PackageInterface $target)
	{
		parent::update($repo, $initial, $target);

		//
		// Don't attempt to remove anything if the initial package didn't have Opus support
		//

		if ($this->checkFrameworkSupport($initial)) {
			$this->loadInstallationMap();

			$old_handled_packages = array_keys($this->buildPackageMap($initial));

			foreach ($this->installationMap as $path => $current_package_names) {
				$this->installationMap[$path] = array_diff(
					$current_package_names,
					$old_handled_packages
				);
			}

			$this->cleanup();

			$this->saveInstallationMap();
		}

		//
		// TODO: Currently updates just copy over files that conflict.  Installations do
		// diffs because we don't want a package or a new package to overwrite something
		// already handled by another or in the framework itself.  Update behavior should
		// be configurable.  It might make sense to track which package a conflicting file
		// is using and copy only if this is the same package.  It may also be useful to
		// add hints for some files (like configuration files) that will diff in all cases.
		// These are just some initial ideas, but for now this should be OK most likely.
		//

		if ($this->checkFrameworkSupport($target)) {
			$this->loadInstallationMap();

			$this->copyMap(
				$this->buildPackageMap($target),
				$conflicts
			);

			if (count($conflicts)) {
				foreach ($conflicts as $a => $b) {
					copy($a, $b);
				}
			}

			$this->saveInstallationMap();
		}

		$this->io->write(PHP_EOL);
	}


	/**
	 * Uninstalls a package
	 *
	 * @access public
	 * @param InstalledRepositoryInterface $repo The repository for installed packages
	 * @param PackageInterface $package The package to uninstall
	 * @return void
	 */
	public function uninstall(InstalledRepositoryInterface $repo, PackageInterface $package)
	{
		parent::uninstall($repo, $package);

		//
		// Return immediately if we have no information relevant to our installer
		//

		if (!$this->checkFrameworkSupport($package)) {
			return;
		}

		$this->loadInstallationMap();

		foreach ($this->installationMap as $path => $packages) {
			if (($key = array_search($package->getName(), $packages)) !== FALSE) {
				unset($this->installationMap[$path][$key]);
			}
		}

		$this->cleanup();
		$this->saveInstallationMap();

		parent::uninstall($repo, $package);
	}


	/**
	 * Builds a package map
	 *
	 * The package map is an array of packages to their installation sources and destinations.
	 * This will resolve any globs for external integration packages.  An external integration
	 * package *cannot* handle installation of a package it does not depend on, so it looks
	 * through the requires to determine this.
	 *
	 * @access private
	 * @param PackageInterface $package The package to build a map for
	 * @return array The package map
	 */
	private function buildPackageMap($package)
	{
		$package_map  = array();
		$requirements = $package->getRequires();
		$extra        = $package->getExtra();
		$mappings     = $extra[self::NAME][$this->framework];

		foreach ($mappings as $element => $value) {
			if (is_array($value)) {

				//
				// Check for external mapping support
				//

				if ($element != $package->getName() && !$this->externalMapping) {
					throw new \Exception(sprintf(
						'Cannot perform external mapping for %s, disabled',
						$element
					));
				}

				if (strpos($element, '*') !== FALSE) {
					$parts   = explode('*', $element);
					$parts   = array_map(function($v) { return preg_quote($v, '#'); }, $parts);
					$element = implode('(.*)', $parts);
				}

				foreach ($requirements as $link) {
					if (preg_match('#' . $element . '#', $link->getTarget())) {
						$package_map[$link->getTarget()] = $value;
					}
				}

				$package_map[$element] = $value;

			} elseif (is_string($value)) {

				if (!isset($package_map[$package->getName()])) {
					$package_map[$package->getName()] = array();
				}

				$package_map[$package->getName()][$element] = $value;

			} else {
				throw new \Exception (sprintf(
					'Ivalid element %s of type %s',
					$element,
					gettype($value)
				));
			}
		}

		return $package_map;
	}


	/**
	 * Checks whether or not our framework is supported by this opus package
	 *
	 * @access private
	 * @param PackageInterface $package The package to build a map for
	 * @return boolean TRUE if the package supports our framework, FALSE otherwise
	 */
	private function checkFrameworkSupport($package)
	{
		$extra = $package->getExtra();

		if (!isset($extra[self::NAME])) {
			return FALSE;
		}

		if (!isset($extra[self::NAME][$this->framework])) {
			return FALSE;
		}

		return $this->enabled;
	}


	/**
	 * Cleans up our map and installations, removing any files no longer mapped to packages
	 *
	 * @access private
	 * @return void
	 */
	private function cleanup()
	{
		foreach ($this->installationMap as $path => $packages) {
			if (count($packages)) {
				continue;
			}

			$installation_path = ltrim($path, '/\\' . DIRECTORY_SEPARATOR);
			$installation_path = getcwd() . DIRECTORY_SEPARATOR . $installation_path;

			if (is_dir($installation_path)) {
				$children = array_merge(
					glob($installation_path . DIRECTORY_SEPARATOR . '/*'),
					glob($installation_path . DIRECTORY_SEPARATOR . '/.*')
				);

				//
				// Children should be at least two (one entry for current dir and another)
				// for parent dir.  Our install map is sorted by the length of the path
				// so we should only be trying to remove directories which have already had
				// other tails related/created children removed.
				//

				if (count($children) > 2) {
					continue;
				}

				if (!rmdir($installation_path)) {
					throw new \Exception(sprintf(
						'Error removing empty directory %s',
						$path
					));
				}

			} else {
				if (!unlink($installation_path)) {
					throw new \Exception(sprintf(
						'Error removing unused file %s',
						$path
					));
				}
			}

			unset($this->installationMap[$path]);
		}
	}


	/**
	 * Copies a source directory to a destination directory
	 *
	 * @access private
	 * @param string $source The source directory
	 * @param string|array $target The destination directory or a map of subdirs to destinations
	 * @param string $entry_name The name for the entry in the installation map
	 * @return array An array of conflicts
	 */
	private function copy($source, $target, $entry_name = NULL)
	{
		$conflicts = array();

		if (is_array($target)) {
			foreach ($target[$entry_name] as $sub_directory => $destination) {
				$sub_directory = trim($sub_directory, '/\\' . DIRECTORY_SEPARATOR);
				$destination   = trim($destination, '/\\' . DIRECTORY_SEPARATOR);

				$conflicts = array_merge(
					$this->copy(
						$source  . DIRECTORY_SEPARATOR . $sub_directory,
						getcwd() . DIRECTORY_SEPARATOR . $destination,
						$entry_name
					),
					$conflicts
				);
			}

			return $conflicts;
		}

		foreach (glob($source . DIRECTORY_SEPARATOR . '*') as $path) {

			$parts       = explode(DIRECTORY_SEPARATOR, $path);
			$item        = array_pop($parts);
			$destination = $target . DIRECTORY_SEPARATOR . $item;

			//
			// Once we have the target name, we want to normalize our path
			//

			$path = realpath($path);

			if (is_dir($path)) {
				if (file_exists($destination)) {
					if (!is_dir($destination)) {
						throw new \Exception(sprintf(
							'Cannot install, conflicting file at %s; should be a directory.',
							$destination
						));
					}

				} elseif (!mkdir($destination)) {
					throw new \Exception(sprintf(
						'Cannot install, unable to create directory at %s',
						$destination
					));
				}

				$conflicts = array_merge(
					$this->copy($path, $destination, $entry_name),
					$conflicts
				);

			} elseif (is_file($path)) {

				$conflict = FALSE;

				if (file_exists($destination)) {
					if (!is_file($destination)) {
						throw new \Exception(sprintf(
							'Cannot install, conflicting directory at %s; should be a file.',
							$destination
						));
					}

					$a = preg_replace('/\s/', '', file_get_contents($path));
					$b = preg_replace('/\s/', '', file_get_contents($destination));

					if ($a !== $b) {
						$conflict         = TRUE;
						$conflicts[$path] = $destination;
					}
				}

				if (!$conflict) {
					copy($path, $destination);
				}
			}

			$relative_path = str_replace(getcwd(), '', $destination);

			if (!isset($this->installationMap[$relative_path])) {
				$this->installationMap[$relative_path] = array();
			}

			$this->installationMap[$relative_path][] = $entry_name;
		}

		return $conflicts;
	}


	/**
	 * Copies files over from a package map
	 *
	 * @access private
	 * @param array $package_map A package map to lookup sources and destinations
	 * @param array $conflicts A reference which will be loaded up with conflicts
	 * @return void
	 */
	private function copyMap($package_map, &$conflicts = NULL)
	{
		$conflicts = array();
		$manager   = $this->composer->getRepositoryManager();
		$packages  = $manager->getLocalRepository()->getPackages();

		foreach ($packages as $package) {
			$package_name = $package->getName();
			$package_root = $this->getInstallPath($package);

			if (!isset($package_map[$package_name])) {
				continue;
			}

			$this->io->write(sprintf(
				self::TAB . 'Copying files from %s',
				substr($package_root, strlen(getcwd()))
			));

			$conflicts = array_merge(
				$this->copy($package_root, $package_map, $package_name),
				$conflicts
			);
		}
	}


	/**
	 * Loads the Opus installation map
	 *
	 * @access private
	 * @return void
	 */
	private function loadInstallationMap()
	{
		if (file_exists($this->mapFile)) {
			if (!is_readable($this->mapFile) || is_dir($this->mapFile)) {
				throw new \Exception(sprintf(
					'Cannot read map file at %s',
					$this->mapFile
				));
			}

			$this->installationMap = json_decode(file_get_contents($this->mapFile), TRUE);

			if ($this->installationMap === NULL) {
				throw new \Exception(sprintf(
					'Broken map file at %s',
					$this->mapFile
				));
			}
		}
	}


	/**
	 * Resolve conflicts by prompting the user for action
	 *
	 * @access private
	 * @param array $conflicts A list of conflicts $source => $destination
	 * @return void
	 */
	private function resolve($conflicts)
	{
		$this->io->write(
			PHP_EOL . self::TAB . 'The following conflicts were found:' . PHP_EOL
		);

		foreach ($conflicts as $a => $b) {
			$answer = NULL;

			while (!$answer || $answer == 'd') {
				$answer = $this->io->ask(sprintf(
					self::TAB . '- %s [o=overwrite (default), k=keep, d=diff]: ',
					substr($b, strlen(getcwd()))
				), 'o');

				switch(strtolower($answer[0])) {
					case 'o':
						copy($a, $b);
						break;
					case 'k':
						break;
					case 'd':
						$this->io->write(PHP_EOL . self::diff($a, $b) . PHP_EOL);
						break;
					default:
						$answer = NULL;
						break;
				}
			}
		}
	}


	/**
	 * Saves the Opus installation map
	 *
	 * @access private
	 * @return void
	 */
	private function saveInstallationMap()
	{
		uksort($this->installationMap, function($a, $b) {
			if (strlen($a) == strlen($b)) {
				return 0;
			}

			if (strlen($a) > strlen($b)) {
				return -1;
			}

			return 1;
		});


		if (file_exists($this->mapFile)) {
			if (!is_writable($this->mapFile) || is_dir($this->mapFile)) {
				throw new \Exception(sprintf(
					'Cannot write to map file at %s',
					$this->mapFile
				));
			}

		}

		if (!file_put_contents($this->mapFile, json_encode($this->installationMap))) {
			throw new \Exception(sprintf(
				'Error while saving map file at %s',
				$this->mapFile
			));
		}
	}
}