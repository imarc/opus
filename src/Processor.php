<?php
namespace Opus;

use Composer\Composer;
use Composer\IO\IOInterface;
use Composer\Installer\PackageEvent;
use Composer\Plugin\PluginInterface;
use Composer\Package\PackageInterface;
use Composer\DependencyResolver\Operation\InstallOperation;
use Composer\DependencyResolver\Operation\UninstallOperation;
use Composer\DependencyResolver\Operation\UpdateOperation;
use Composer\EventDispatcher\EventSubscriberInterface;

use Jfcherng\Diff\Differ;
use Jfcherng\Diff\Factory\RendererFactory;
use Jfcherng\Diff\Renderer\RendererInterface;

use RuntimeException;

/**
 * Composer processor
 *
 * @copyright iMarc, LLC
 * @author Matthew J. Sahagian [mjs] <matt@imarc.net>
 * @license MIT (See the LICENSE file at the root of this distribution)
 */
class Processor implements PluginInterface, EventSubscriberInterface
{
	/**
	 * @var string
	 */
	const NAME = 'opus';

	/**
	 * @var string
	 */
	const TAB  = '    ';

	/**
	 * A list of common directory separators, including the system designated one
	 *
	 * @var array<string>
	 */
	static private $separators = ['\\', '/', DIRECTORY_SEPARATOR];

	/**
	 * @var Composer
	 */
	private $composer;

	/**
	 * @var RendererInterface
	 */
	private $differ;

	/**
	 * Check for external mapping
	 *
	 * @access private
	 * @var bool
	 */
	private $externalMapping = FALSE;

	/**
	 * Whether or not opus support is enabled
	 *
	 * @var boolean
	 */
	private $enabled = TRUE;

	/**
	 * The integrity level
	 *
	 * @var string
	 */
	private $integrity = 'medium';

	/**
	 * @var IOInterface
	 */
	private $interface;

	/**
	 * The framework as defined by our extra options
	 *
	 * @var string
	 */
	private $framework;

	/**
	 *  The opus map
	 *
	 * @var array
	 */
	private $map = array();

	/**
	 * The map file we read/write from/to
	 *
	 * @var string
	 */
	private $mapFile;


	/**
	 * @var array<string, string|array<string, int>>
	 */
	static public function getSubscribedEvents(): array
	{
		return [
			'post-package-install'   => 'pkgInstall',
			'post-package-uninstall' => 'pkgUnintstall',
			'pre-package-update'     => 'pkgUpdate',
		];
	}


	/**
	 * Activate the installer
	 */
	 public function activate(Composer $composer, IOInterface $interface)
	 {
		 $this->composer  = $composer;
		 $this->interface = $interface;
		 $this->differ    = RendererFactory::make('Unified');
		 $this->mapFile   = getcwd() . DIRECTORY_SEPARATOR . self::NAME . '.map';
		 $root_package    = $this->composer->getPackage();

		 if ($config = $root_package->getExtra()[self::NAME] ?? array()) {
			$options = $config['options'] ?? array();

			if (isset($config['enabled'])) {
				$this->enabled = $config['enabled'];
			}

			if (isset($options['framework'])) {
				$this->framework = $options['framework'];
			}

			if (isset($options['integrity'])) {
				$this->integrity = $options['integrity'];
			}

			if (isset($options['external-mapping'])) {
				$this->externalMapping = $options['external-mapping'];
			}

		 }

		 if (!$this->framework) {
			 $this->framework = $root_package->getName();
		 }

		 if (getenv('OPUS_DISABLED')) {
			$this->enabled = FALSE;
		 }

		if (file_exists($this->mapFile)) {
			$this->map = json_decode(file_get_contents($this->mapFile), TRUE);
		} else {
			$this->map = array();
		}
	 }


	/**
	 *
	 */
	public function deactivate(Composer $composer, IOInterface $io)
	{
		foreach ($this->map as $path => $packages) {
			if ($path == '__CHECKSUMS__') {
				continue;
			}

			if (count($packages)) {
				//
				// Sort Our Package Names
				//

				sort($this->map[$path]);
				continue;
			}

			$installation_path = $this->ltrim($path);
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
							$this->interface->write(sprintf(
								self::TAB . 'Warning: unable to remove empty directory %s',
								$path
							));
							break;

						case 'high':
							throw new RuntimeException(sprintf(
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
							$this->interface->write(sprintf(
								self::TAB . 'Warning: unable to remove unused file %s; remove manually',
								$path
							));
							break;

						case 'high':
							throw new RuntimeException(sprintf(
								'Error removing unused file %s, restore file or check permissions and try again',
								$path
							));
					}
				}
			}

			unset($this->map[$path]);
			unset($this->map['__CHECKSUMS__'][$path]);
		}

		//
		// Sort our paths
		//

		if (isset($this->map['__CHECKSUMS__'])) {
			ksort($this->map['__CHECKSUMS__']);
		}

		ksort($this->map);

		file_put_contents($this->mapFile, json_encode($this->map));
	}


	/**
	 *
	 */
	public function uninstall(Composer $composer, IOInterface $io)
	{
		//
		// Don't ever uninstall Opus
		//
	}


	/**
	 *
	 */
	public function pkgInstall(PackageEvent $event)
	{
		/**
		 * @var InstallOperation
		 */
		$operation = $event->getOperation();
		$package   = $operation->getPackage();

		if (!$this->checkFrameworkSupport($package)) {
			return;
		}

		$result = array();

		$this->copy($package, $result);
		$this->fix($result);
	}


	/**
	 *
	 */
	public function pkgUninstall(PackageEvent $event)
	{
		/**
		 * @var UninstallOperation
		 */
		$operation = $event->getOperation();
		$package   = $operation->getPackage();

	}


	/**
	 *
	 */
	public function pkgUpdate(PackageEvent $event)
	{
		/**
		 * @var UpdateOperation
		 */
		$operation   = $event->getOperation();
		$cur_package = $operation->getInitialPackage();
		$new_package = $operation->getTargetPackage();

		if (!$this->checkFrameworkSupport($cur_package)) {
			$old_packages = array_keys($this->build($cur_package));

			foreach ($this->map as $path => $cur_packages) {
				$this->map[$path] = array_diff(
					$cur_packages,
					$old_packages
				);
			}
		}

		if ($this->checkFrameworkSupport($new_package)) {
			$result = array();

			$this->copy($new_package, $result);
			$this->fix($result);
		}
	}


	/**
	 * Checks whether or not our framework is supported by this opus package
	 */
	private function checkFrameworkSupport(PackageInterface $package): bool
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
	 * Attempts to create a directory and make sure it's writable while mapping
	 * any created directories.
	 *
	 * @param string $directory The directory to try and create
	 * @param string $entry_name The entry name to map under
	 * @return void
	 */
	private function createDirectory(string $directory, string $entry_name = NULL)
	{
		$directory = str_replace(DIRECTORY_SEPARATOR, '/', $directory);
		$directory = str_replace('\\', '/', $directory);
		$directory = str_replace('/',  '/', $directory);
		$directory = $this->rtrim($directory);

		if (!file_exists($directory)) {
			$this->createDirectory(
				pathinfo($directory, PATHINFO_DIRNAME),
				$entry_name
			);

			if (!mkdir($directory)) {
				throw new RuntimeException(sprintf(
					'Cannot install, failure while creating requisite directory "%s"',
					$directory
				));
			}

		} else {
			if (!is_dir($directory)) {
				throw new RuntimeException(sprintf(
					'Cannot install, requisite path "%s" exists, but is not a directory',
					$directory
				));
			}

			if (!is_writable($directory)) {
				throw new RuntimeException(sprintf(
					'Cannot install, requisite directory "%s" is not writable',
					$directory
				));
			}
		}

		$this->commit($directory, $entry_name);
	}


	/**
	 * Builds a package map
	 *
	 * The package map is an array of packages to their installation sources and destinations.
	 * This will resolve any globs for external integration packages.  An external integration
	 * package *cannot* handle installation of a package it does not depend on, so it looks
	 * through the requires to determine this.
	 */
	private function build(PackageInterface $package): array
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
					throw new RuntimeException(sprintf(
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
				throw new RuntimeException (sprintf(
					'Ivalid element %s of type %s', $element, gettype($value)
				));
			}
		}

		return $package_map;
	}


	/**
	 * Commit a destination to the map
	 */
	private function commit(string $dst, $entry_name)
	{
		$base_path = str_replace(DIRECTORY_SEPARATOR, '/', getcwd());
		$opus_path = str_replace($base_path, '', $dst);

		if (!isset($this->map[$opus_path])) {
			$this->map[$opus_path] = array();
		}

		if (!in_array($entry_name, $this->map[$opus_path])) {
			$this->map[$opus_path][] = $entry_name;

		} else {

			//
			// It's already mapped, what more do you want?
			//

		}
	}


	/**
	 * Copies files over from a package map
	 */
	private function copy(PackageInterface $package, array &$result = array()): void
	{
		$map      = $this->build($package);
		$manager  = $this->composer->getRepositoryManager();
		$packages = $manager->getLocalRepository()->getPackages();

		foreach ($packages as $package) {
			$name = $package->getName();
			$root = $this->rtrim($package->getTargetDir());

			if (!isset($map[$name])) {
				continue;
			}

			$this->interface->write(sprintf(
				self::TAB . 'Copying files from %s',
				substr($root, strlen(getcwd()))
			));

			foreach ($map[$name] as $a => $b) {
				$a_path = $this->trim($a);
				$b_path = $this->ltrim($b);
				$result = array_replace_recursive(
					$this->copyFiles(
						$root . DIRECTORY_SEPARATOR . $a_path,
						getcwd() . DIRECTORY_SEPARATOR . $b_path,
						$name
					),
					$result
				);
			}
		}
	}

	/**
	 */
	private function copyFiles(string $src, string $dst, string $entry_name): array
	{
		$src    = $this->rtrim(realpath($src));
		$result = [
			'updates'   => array(),
			'conflicts' => array(),
		];

		if (!$src) {
			throw new RuntimeException(sprintf(
				'Cannot install, bad source entry while trying to install %s', $entry_name
			));
		}

		if (is_file($src)) {

			//
			// If target $b looks like a directory or is a directory, use it as our target dir
			// and the original filename of our source as the file name
			//

			if ($dst[strlen($dst) - 1] == '/' || is_dir($dst)) {
				$dst_dir   = $this->rtrim($dst);
				$file_name = pathinfo($src, PATHINFO_BASENAME);

			//
			// Otherwise, use the directory of the destination as the target dir and the name
			// of the destination as the filename
			//

			} else {
				$dst_dir   = pathinfo($dst, PATHINFO_DIRNAME);
				$file_name = pathinfo($dst, PATHINFO_BASENAME);
			}

			$this->createDirectory($dst_dir, $entry_name);

			$dst = $dst_dir . DIRECTORY_SEPARATOR . $file_name;

			if (file_exists($dst)) {
				if (!is_writable($dst)) {
					throw new RuntimeException(sprintf(
						'Cannot install, cannot write to file at "%s"', $dst
					));
				}

				$new_checksum = md5(preg_replace('/\s/', '', file_get_contents($src)));
				$cur_checksum = md5(preg_replace('/\s/', '', file_get_contents($dst)));

				if ($new_checksum !== $cur_checksum) {
					$result['conflicts'][$src] = $dst;
				}
			}

			if (!isset($result['conflicts'][$src])) {
				copy($src, $dst);

				$base_path = str_replace(DIRECTORY_SEPARATOR, '/', getcwd());
				$opus_path = str_replace($base_path, '', $dst);

				$result['updates'][$opus_path] = md5(file_get_contents($dst));
			}

			$this->commit($dst, $entry_name);

		} elseif (is_dir($src)) {
			if (is_file($dst)) {
				throw new RuntimeException(sprintf(
					'Cannot copy source "%s" (directory) to "%s" (file)', $src, $dst
				));
			}

			$dst_dir = $this->rtrim($dst);

			if (in_array($dst[strlen($dst) -1], self::$separators)) {
				$dst_dir .= DIRECTORY_SEPARATOR . pathinfo($src, PATHINFO_BASENAME);
			}

			$this->createDirectory($dst_dir, $entry_name);

			foreach (glob($src . DIRECTORY_SEPARATOR . '{,.}*[!.]', GLOB_BRACE) as $path) {
				$result = array_replace_recursive(
					$this->copyFiles(
						realpath($path),
						$dst_dir . DIRECTORY_SEPARATOR . pathinfo($path, PATHINFO_BASENAME),
						$entry_name
					),
					$result
				);
			}

		} else {
			throw new RuntimeException(sprintf(
				'Cannot copy source "%s", not readable or not a normal file or directory', $src
			));
		}

		return $result;
	}


	/**
	 * Get the difference between two files
	 *
	 * @return string The unified diff between the two
	 */
	private function diff(string $a, string $b)
	{
		$a_code = file($a);
		$b_code = file($b);
		$diff   = new Differ($b_code, $a_code, array(
			'ignoreWhitespace' => TRUE,
			'ignoreNewLines'   => TRUE
		));

		return $this->differ->render($diff);
	}



	/**
	 * Fix any conflicts depending on integrity
	 */
	private function fix(array &$result): bool
	{
		if (!isset($result['conflicts']) || !count($result['conflicts'])) {
			return TRUE;
		}

		$original_checksums = isset($this->map['__CHECKSUMS__'])
			? $this->map['__CHECKSUMS__']
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
					copy($a, $b);
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
					$this->interface->write(
						PHP_EOL . self::TAB . 'The following conflicts were found:' . PHP_EOL
					);

					while (!$answer) {
						$answer = $this->interface->ask(sprintf(
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
								$this->interface->write(PHP_EOL . $this->diff($a, $b) . PHP_EOL);
							default:
								$answer = NULL;
								break;
						}
					}

					break;
			}
		}

		return TRUE;
	}


	/**
	 * Left trim all directory separators
	 */
	private function ltrim(string $path): string
	{
		return rtrim($path, '/\\' . DIRECTORY_SEPARATOR);
	}


	/**
	 * Right trim all directory separators
	 */
	private function rtrim(string $path): string
	{
		return rtrim($path, '/\\' . DIRECTORY_SEPARATOR);
	}


	/**
	 * Trim all directory separators
	 */
	private function trim(string $path): string
	{
		return trim($path, '/\\' . DIRECTORY_SEPARATOR);
	}
}
