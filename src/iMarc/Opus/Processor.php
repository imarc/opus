<?php
namespace iMarc\Opus;

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
	 * A list of common directory separators, including the system designated one
	 *
	 * @static
	 * @access private
	 * @var array
	 */
	static private $separators = ['\\', '/', DIRECTORY_SEPARATOR];


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
		$diff   = new \Diff($b_code, $a_code, array(
			'ignoreWhitespace' => TRUE,
			'ignoreNewLines'   => TRUE
		));

		return $diff->render(new \Diff_Renderer_Text_Unified());
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
		$this->integrity = 'medium';

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

			if (isset($options['integrity'])) {
				$options['integrity'] = strtolower($options['integrity']);
				$valid_levels         = array('low', 'medium', 'high');
				$this->integrity      = in_array($options['integrity'], $valid_levels)
					? $options['integrity']
					: 'medium';
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
		$promise = parent::install($repo, $package);
		$install = function ($value = NULL) use ($repo, $package) {
			if (!getenv('OPUS_DISABLED') && $this->checkFrameworkSupport($package)) {
				$this->loadInstallationMap();

				$this->copyMap(
					$this->buildPackageMap($package),
					$result
				);

				$this->resolve($result);
				$this->saveInstallationMap($result);

				$this->io->write(PHP_EOL);
			}

			return $value;
		};

		if ($promise) {
			return $promise->then($install);
		} else {
			return $install();
		}
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
		$promise = parent::update($repo, $initial, $target);
		$update  = function ($value = NULL) use ($repo, $initial, $target) {
			if (!getenv('OPUS_DISABLED')) {

				//
				// Don't attempt to remove anything if the initial package didn't have Opus support.
				// If it did, however, we will iterate through the installation map and remove package
				// names which the old package handled installation for.
				//
				// We will then save the installation map without writing checksums, so that some paths
				// may be completely empty arrays (i.e. have no packages handling them) and so that the
				// checksums are still the original file checksums.
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

					$this->saveInstallationMap();
				}

				//
				// At this point, if the new package supports opus it will redo-copying and re-add itself
				// to the paths it handles.  This will leave any files it no longer handles with empty
				// arrays from previous action.  In the event of a conflict, the checksum of the
				// destination file will be checked against the original checksums in the installation
				// map and the user will be prompted with additional actions based on their integrity
				// level.
				//

				if ($this->checkFrameworkSupport($target)) {
					$this->loadInstallationMap();

					$this->copyMap(
						$this->buildPackageMap($target),
						$result
					);

					$this->resolve($result);
					$this->saveInstallationMap($result);
				}

				//
				// Lastly, when the remove package has been removed from the installation map and the
				// new package has re-added itself for any files it handles, we will run cleanup.  This
				// will actually remove any unhandled files and directories.
				//

				$this->clean();
				$this->saveInstallationMap();

				$this->io->write(PHP_EOL);
			}
		};

		if ($promise) {
			return $promise->then($update);
		} else {
			return $update();
		}
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
		if (!getenv('OPUS_DISABLED') && $this->checkFrameworkSupport($package)) {
			$this->loadInstallationMap();

			foreach ($this->installationMap as $path => $packages) {
				if (($key = array_search($package->getName(), $packages)) !== FALSE) {
					unset($this->installationMap[$path][$key]);
				}
			}

			$this->clean();
			$this->saveInstallationMap();
		}

		return parent::uninstall($repo, $package);
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
						'Cannot perform external mapping for %s, disabled', $element
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
					'Ivalid element %s of type %s', $element, gettype($value)
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
	private function clean()
	{
		foreach ($this->installationMap as $path => $packages) {
			if ($path == '__CHECKSUMS__') {
				continue;
			}

			if (count($packages)) {
				//
				// Sort Our Package Names
				//

				sort($this->installationMap[$path]);
				continue;
			}

			$installation_path = ltrim($path, '/\\' . DIRECTORY_SEPARATOR);
			$installation_path = getcwd() . DIRECTORY_SEPARATOR . $installation_path;

			if (is_dir($installation_path)) {

				//
				// Here's what to do with a directory
				//

				$children = array_merge(
					glob($installation_path . DIRECTORY_SEPARATOR . '/*'),
					glob($installation_path . DIRECTORY_SEPARATOR . '/.*')
				);

				//
				// Children should be at least two (one entry for current dir and another)
				// for parent dir.  Our install map is sorted by the length of the path
				// so we should only be trying to remove directories which have already had
				// other opus related/created children removed.
				//

				if (count($children) > 2) {
					continue;
				}

				if (!@rmdir($installation_path)) {
					switch ($this->integrity) {
						case 'low':
							break;

						case 'medium':
							$this->io->write(sprintf(
								self::TAB . 'Warning: unable to remove empty directory %s',
								$path
							));
							break;

						case 'high':
							throw new \Exception(sprintf(
								'Error removing empty directory %s',
								$path
							));
					}
				}

			} else {

				//
				// Here's what to do with a file
				//

				if (!@unlink($installation_path)) {
					switch ($this->integrity) {
						case 'low':
							break;

						case 'medium':
							$this->io->write(sprintf(
								self::TAB . 'Warning: unable to remove unused file %s; remove manually',
								$path
							));
							break;

						case 'high':
							throw new \Exception(sprintf(
								'Error removing unused file %s, restore file or check permissions and try again',
								$path
							));
					}
				}
			}

			unset($this->installationMap[$path]);
			unset($this->installationMap['__CHECKSUMS__'][$path]);
		}

		//
		// Sort our paths
		//

		if (isset($this->installationMap['__CHECKSUMS__'])) {
			ksort($this->installationMap['__CHECKSUMS__']);
		}

		ksort($this->installationMap);
	}


	/**
	 * Copies a source directory to a destination directory
	 *
	 * @access private
	 * @param string $source The source file/directory
	 * @param string|array $destination The destination file/directory
	 * @param string $entry_name The name for the entry in the installation map
	 * @return array An array of conflicts
	 */
	private function copy($a, $b, $entry_name = NULL)
	{
		$a      = rtrim(realpath($a), '/\\' . DIRECTORY_SEPARATOR);
		$result = array('updates' => array(), 'conflicts' => array());

		if (!$a) {
			throw new \Exception(sprintf(
				'Cannot install, bad source entry while trying to install %s', $entry_name
			));
		}

		if (is_file($a)) {

			//
			// If target $b looks like a directory or is a directory, use it as our target dir
			// and the original filename of our source as the file name
			//

			if (in_array($b[strlen($b) - 1], self::$separators) || is_dir($b)) {
				$target_dir = rtrim($b, '/\\' . DIRECTORY_SEPARATOR);
				$file_name  = pathinfo($a, PATHINFO_BASENAME);

			//
			// Otherwise, use the directory of the destination as the target dir and the name
			// of the destination as the filename
			//

			} else {
				$target_dir = pathinfo($b, PATHINFO_DIRNAME);
				$file_name  = pathinfo($b, PATHINFO_BASENAME);
			}

			$this->createDirectory($target_dir, $entry_name);

			$b = $target_dir . DIRECTORY_SEPARATOR . $file_name;

			if (file_exists($b)) {
				if (!is_writable($b)) {
					throw new \Exception(sprintf(
						'Cannot install, cannot write to file at "%s"', $b
					));
				}

				$new_checksum     = md5(preg_replace('/\s/', '', file_get_contents($a)));
				$current_checksum = md5(preg_replace('/\s/', '', file_get_contents($b)));

				if ($new_checksum !== $current_checksum) {
					$result['conflicts'][$a] = $b;
				}
			}

			if (!isset($result['conflicts'][$a])) {
				copy($a, $b);

				$base_path = str_replace(DIRECTORY_SEPARATOR, '/', getcwd());
				$opus_path = str_replace($base_path, '', $b);

				$result['updates'][$opus_path] = md5(file_get_contents($b));
			}

			$this->map($b, $entry_name);

		} elseif (is_dir($a)) {
			if (is_file($b)) {
				throw new \Exception(sprintf(
					'Cannot copy source "%s" (directory) to "%s" (file)', $a, $b
				));
			}

			$target_dir = rtrim($b, '/\\' . DIRECTORY_SEPARATOR);

			if (in_array($b[strlen($b) -1], self::$separators)) {
				$target_dir .= DIRECTORY_SEPARATOR . pathinfo($a, PATHINFO_BASENAME);
			}

			$this->createDirectory($target_dir, $entry_name);

			foreach (glob($a . DIRECTORY_SEPARATOR . '{,.}*[!.]', GLOB_BRACE) as $path) {
				$result = array_replace_recursive(
					$this->copy(
						realpath($path),
						$target_dir . DIRECTORY_SEPARATOR . pathinfo($path, PATHINFO_BASENAME),
						$entry_name
					),
					$result
				);
			}

		} else {
			throw new \Exception(sprintf(
				'Cannot copy source "%s", not readable or not a normal file or directory', $a
			));
		}

		return $result;
	}


	/**
	 * Copies files over from a package map
	 *
	 * @access private
	 * @param array $package_map A package map to lookup sources and destinations
	 * @param array $result A reference which will be loaded up update and conflict information
	 * @return void
	 */
	private function copyMap($package_map, &$result = NULL)
	{
		$result   = (array) $result;
		$manager  = $this->composer->getRepositoryManager();
		$packages = $manager->getLocalRepository()->getPackages();

		foreach ($packages as $package) {
			$package_name = $package->getName();
			$package_root = rtrim($this->getInstallPath($package), '/\\' . DIRECTORY_SEPARATOR);

			if (!isset($package_map[$package_name])) {
				continue;
			}

			$this->io->write(sprintf(
				self::TAB . 'Copying files from %s',
				substr($package_root, strlen(getcwd()))
			));

			foreach ($package_map[$package_name] as $a => $b) {
				$a =  trim($a, '/\\' . DIRECTORY_SEPARATOR);
				$b = ltrim($b, '/\\' . DIRECTORY_SEPARATOR);
				$result = array_replace_recursive(
					$this->copy(
						$package_root . DIRECTORY_SEPARATOR . $a,
						getcwd() . DIRECTORY_SEPARATOR . $b,
						$package_name
					),
					$result
				);
			}
		}
	}


	/**
	 * Attempts to create a directory and make sure it's writable while mapping
	 * any created directories.
	 *
	 * @param string $directory The directory to try and create
	 * @param string $entry_name The entry name to map under
	 * @return void
	 */
	private function createDirectory($directory, $entry_name = NULL)
	{
		$directory = str_replace(DIRECTORY_SEPARATOR, '/', $directory);
		$directory = str_replace('\\', '/', $directory);
		$directory = str_replace('/',  '/', $directory);
		$directory = rtrim($directory, '/\\' . DIRECTORY_SEPARATOR);

		if (!file_exists($directory)) {
			$this->createDirectory(
				pathinfo($directory, PATHINFO_DIRNAME),
				$entry_name
			);

			if (!mkdir($directory)) {
				throw new \Exception(sprintf(
					'Cannot install, failure while creating requisite directory "%s"',
					$directory
				));
			}

		} else {
			if (!is_dir($directory)) {
				throw new \Exception(sprintf(
					'Cannot install, requisite path "%s" exists, but is not a directory',
					$directory
				));
			}

			if (!is_writable($directory)) {
				throw new \Exception(sprintf(
					'Cannot install, requisite directory "%s" is not writable',
					$directory
				));
			}
		}

		$this->map($directory, $entry_name);
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
					'Cannot read map file at %s', $this->mapFile
				));
			}

			$this->installationMap = json_decode(file_get_contents($this->mapFile), TRUE);

			if ($this->installationMap === NULL) {
				throw new \Exception(sprintf(
					'Broken map file at %s', $this->mapFile
				));
			}
		}
	}


	/**
	 * Maps a package entry name to an opus path
	 *
	 * This essentially ensures that our map keeps track of which packages have touched a given
	 * destination file.  If all packages are removed for a given file, then the file can be
	 * safely removed as well.
	 *
	 * @access private
	 * @param string $dest The Destination to map
	 * @param string $entry_name The entry name to map under
	 * @return void
	 */
	private function map($destination, $entry_name)
	{
		$base_path = str_replace(DIRECTORY_SEPARATOR, '/', getcwd());
		$opus_path = str_replace($base_path, '', $destination);

		if (!isset($this->installationMap[$opus_path])) {
			$this->installationMap[$opus_path] = array();
		}

		if (!in_array($entry_name, $this->installationMap[$opus_path])) {
			$this->installationMap[$opus_path][] = $entry_name;

		} else {

			//
			// It's already mapped, what more do you want?
			//

		}
	}


	/**
	 * Resolve conflicts by prompting the user for action
	 *
	 * @access private
	 * @param array $result A copymap result containing updates and conflicts
	 * @return boolean TRUE if the user overwrite with the new version, FALSE otherwise
	 */
	private function resolve(&$result)
	{
		if (!isset($result['conflicts']) || !count($result['conflicts'])) {
			return;
		}

		$original_checksums = isset($this->installationMap['__CHECKSUMS__'])
			? $this->installationMap['__CHECKSUMS__']
			: array();

		foreach ($result['conflicts'] as $a => $b) {
			$base_path        = str_replace(DIRECTORY_SEPARATOR, '/', getcwd());
			$opus_path        = str_replace($base_path, '', $b);
			$current_checksum = md5(@file_get_contents($b));
			$new_checksum     = md5(@file_get_contents($a));
			$old_checksum     = isset($original_checksums[$opus_path])
				? $original_checksums[$opus_path]
				: md5(FALSE);

			switch ($this->integrity) {
				case 'low':
					copy ($a, $b);
					break;

				case 'medium':
					if ($new_checksum == $old_checksum) {

						//
						// A conflict is only raised if the destinaton differs from the source.
						// However, if we "keep" a file on a previous conflict, the map will be
						// updated with the new checksum even though the file is not copied.  By
						// checking if the new checksum is equal to the old, we can determine if
						// the file has actually changed in the package.  If it has not, we
						// don't need to do anything.
						//

						break;

					} elseif ($old_checksum == $current_checksum) {

						//
						// If we didn't pass the previous condition, then it means that the file
						// in the package has changed since the previous copy or keep.  So now
						// we want to see if the old checksum is equivalent to the current file.
						// This would imply we have not changed it.  If we have no customizations
						// then we can  safely copy the file and update the checksum in the map.
						//

						copy($a, $b);
						$result['updates'][$opus_path] = $new_checksum;
						break;

					} else {

						//
						// Lastly, if our new checksum is not the same as our old one and our
						// old one is not our current one, we want to give the user the option
						// to resolve this as if it were 'high' integrity.
						//

					}

				case 'high':
					$answer = NULL;
					$this->io->write(
						PHP_EOL . self::TAB . 'The following conflicts were found:' . PHP_EOL
					);

					while (!$answer) {
						$answer = $this->io->ask(sprintf(
							self::TAB . '- %s [o=overwrite (default), k=keep, d=diff]: ',
							$opus_path
						), 'o');

						switch(strtolower($answer[0])) {
							case 'o':
								copy($a, $b);
								$result['updates'][$opus_path] = $new_checksum;
								break;

							case 'k':
								$result['updates'][$opus_path] = $new_checksum;
								break;

							case 'd':
								$this->io->write(PHP_EOL . self::diff($a, $b) . PHP_EOL);
							default:
								$answer = NULL;
								break;
						}
					}

					break;
			}
		}
	}


	/**
	 * Saves the Opus installation map
	 *
	 * @access private
	 * @param $result A copyMap result
	 * @return void
	 */
	private function saveInstallationMap($result = array())
	{
		//
		// Temporarily remove original checksums
		//

		if (isset($this->installationMap['__CHECKSUMS__'])) {
			$original_checksums = $this->installationMap['__CHECKSUMS__'];

			unset($this->installationMap['__CHECKSUMS__']);

		} else {
			$original_checksums = array();
		}

		if ($result && isset($result['updates'])) {
			foreach ($result['updates'] as $opus_path => $checksum) {
				$original_checksums[$opus_path] = $checksum;
			}
		}

		$this->installationMap['__CHECKSUMS__'] = $original_checksums;

		//
		// Write our map
		//

		if (file_exists($this->mapFile)) {
			if (!is_writable($this->mapFile) || is_dir($this->mapFile)) {
				throw new \Exception(sprintf(
					'Cannot write to map file at %s', $this->mapFile
				));
			}

		}

		if (!file_put_contents($this->mapFile, json_encode($this->installationMap))) {
			throw new \Exception(sprintf(
				'Error while saving map file at %s', $this->mapFile
			));
		}
	}
}
