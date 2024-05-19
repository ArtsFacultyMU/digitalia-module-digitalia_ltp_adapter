<?php

namespace Drupal\digitalia_ltp_adapter;

use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\media\Entity\Media;
use Drupal\file\Entity\File;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\File\FileSystem;
use Drupal\Core\Entity\EntityInterface;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;


class Utils
{
	private $filesystem;
	private $file_repository;
	private $config;
	private $content_types_fields;

	public function __construct()
	{
		$this->filesystem = \Drupal::service('file_system');
		$this->file_repository = \Drupal::service('file.repository');
		$this->config = \Drupal::config('digitalia_ltp_adapter.admin_settings');
		$this->content_types_fields = $this->parseFieldConfiguration();
	}

	public function getConfig()
	{
		return $this->config;
	}

	public function getEnabledLtpSystem()
	{
		return $this->config->get["enabled_ltp_systems"];
	}

	public function getContentTypesFields()
	{
		return $this->content_types_fields;
	}


	/**
	 * Gets list of all content types which are to be archived
	 *
	 * @return array
	 *   Array of content types
	 */
	public function getEnabledContentTypes()
	{
		$content_types = array();
		foreach ($this->getContentTypesFields() as $type => $_value) {
			$content_types[$type] = 1;
		}

		return $content_types;
	}

	/**
	 * Tries to lock directory. Blocks for at most $timeout seconds
	 *
	 * @param String $dirpath
	 *   Full path to directory to lock
	 *
	 * @param int $interval
	 *   Lock checking interval in seconds
	 *
	 * @param int $timeout
	 *   Max blocking time in seconds
	 *
	 * @return bool
	 *   True if successfull
	 */
	public function checkAndLock(String $dirpath, int $interval = 2, int $timeout = 20)
	{
		//\Drupal::logger('digitalia_ltp_adapter')->debug("checkAndLock: start");
		$this->filesystem->prepareDirectory($dirpath, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS);
		$total_wait = 0;

		
		//while ($this->isLocked($dirpath)) {
		while (!fopen($this->filesystem->realpath($dirpath . "/lock"), "x")) {
			sleep($interval);
			$total_wait += $interval;
			if ($total_wait >= $timeout) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Removes lock from a directory
	 *
	 * @param String $dirpath
	 *   Directory to unlock
	 */
	public function removeLock(String $dirpath)
	{
		$this->filesystem->delete($dirpath . "/lock");
	}

	/**
	 * Checks for lock on directory
	 *
	 * @param String $dirpath
	 *   Path to directory
	 *
	 * @return bool
	 *   True if locked
	 */
	public function isLocked(String $dirpath)
	{
		clearstatcache(true, $dirpath . "/lock");
		return file_exists($dirpath . "/lock");
	}

	/**
	 * Creates filename/directory friendly uid for entities
	 *
	 * @param EntityInterface $entity
	 *   Entity for which to generate uid
	 *
	 * @return
	 *   String uid
	 */
	public function getEntityUID(EntityInterface $entity)
	{
		switch ($entity->getEntityTypeId()) {
		case "node":
			$prefix = "nid";
			break;
		case "media":
			$prefix = "mid";
			break;
		case "taxonomy_term":
			$prefix = "tid";
			break;
		case "taxonomy_vocabulary":
			$prefix = "vid";
			break;
		case "user":
			$prefix = "uid";
			break;
		default:
			$prefix = "id";
			break;
		}

		return $prefix . "_" . $entity->id();
	}


	/**
	 * Gets full entity uid for export directory structure (base object directory)
	 *
	 * @param EntityInterface $entity
	 *   Entity for which to generate full uid
	 *
	 * @return
	 *   String full uid
	 */
	public function getFullEntityUID(EntityInterface $entity)
	{
		return $this->config->get('site_name') . "_" . $this->getEntityUID($entity);

	}

	/**
	 * Removes all generated files from previous exports
	 *
	 * @param $entity
	 *   Entity to be exported
	 */
	public function preExportCleanup($entity, $base_url)
	{
		$filename = $this->getFullEntityUID($entity);
		$filepath = $base_url . "/" . $filename;

		$this->filesystem->deleteRecursive($filepath);
	}


	/**
	 * Adds a directory to ingest queue
	 *
	 * @param Array $params
	 *   Array with parameters
	 */
	public function addToQueue(Array $params)
	{
		$queue = \Drupal::service('queue')->get('digitalia_ltp_adapter_export_queue');
		\Drupal::logger('digitalia_ltp_adapter')->debug("adding to queue");
		try {
			$queue->createItem($params);
		} catch (Exception $e) {
			\Drupal::logger('digitalia_ltp_adapter')->error("Object '" . $params['params'] . "'couldn't be added to queue.");
			\Drupal::logger('digitalia_ltp_adapter')->error($e->getMessage());
		}
	}

	/**
	 * Removes directory from export queue
	 *
	 * @param String $directory
	 *   Name of directory to remove from ingest queue
	 *
	 * @param bool $all
	 *   Set to true to delete all intstances of $directory from queue
	 */
	public function removeFromQueue(String $directory, bool $all = false)
	{
		$queue = \Drupal::service('queue')->get('digitalia_ltp_adapter_export_queue');
		$items = array();
		while ($item = $queue->claimItem()) {
			if ($item->data["directory"] == $directory) {
				$queue->deleteItem($item);

				if (!$all) {
					return;
				}
			} else {
				array_push($items, $item);
			}
		}

		foreach ($items as $item) {
			$queue->releaseItem($item);
		}
	}

	/**
	 * Gets entity field in default language
	 *
	 * @param $entity
	 *   Drupal entity
	 *
	 * @param String $field
	 *   Name of field
	 *
	 * @return
	 *   Value of field
	 */
	public function getEntityField($entity, String $field)
	{
		try {
			$value = $entity->get($field);
		} catch (\Exception $e) {
			return;
		}

		$value = $entity->get($field)->getValue();

		// Untranslated entities store values directly, translated entities are more complex
		if (is_array($value)) {
			return $entity->get($field)->getValue()[0]["value"];
		}

		return $value;
	}

	/**
	 * @param $pathdir
	 *   Path, where the soon to be archived directory lies (with trailng slash)
	 *
	 * @param $directory
	 *   Directory to be archived
	 *
	 * @return String
	 *   Name of created archive
	 */
	public function zipDirectory(String $base_url, String $directory, bool $include_root_dir)
	{
		$zip_file = $directory . ".zip";

		// zip file must exist
		fopen($this->filesystem->realpath($base_url . "/" . $zip_file), "w");
		$zip = \Drupal::service('plugin.manager.archiver')->getInstance(['filepath' => $base_url . "/" . $zip_file])->getArchive();

		$files = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator($base_url . "/" . $directory . "/"),
			\RecursiveIteratorIterator::LEAVES_ONLY
		);


		if ($include_root_dir) {
			// Add root directory, otherwise ARCLib fails to validate zip (possibly resolved by #143)
			$zip->addEmptyDir($directory);
			$this->addFilesToZip($zip, $files, $base_url . "/", $directory . "/lock");
		} else {
			$this->addFilesToZip($zip, $files, $base_url . "/" . $directory . "/", "lock");
		}

		$zip->close();

		return $directory . ".zip";
	}

	/**
	 * Adds files to zip object, removes lock from archive
	 *
	 * @param $zip
	 *   Zip object
	 *
	 * @param Array $files
	 *   List of files to archive
	 *
	 * @param String $base_path
	 *   Base directory from which relative paths inside archive are taken
	 *
	 * @param String $lock_relative_path
	 *   Relative path to lock file
	 */
	private function addFilesToZip($zip, $files, String $base_path, String $lock_relative_path)
	{
		$real_base_path = $this->filesystem->realpath($base_path);

		foreach ($files as $file) {
			if (!$file->isDir()) {
				$file_path = $this->filesystem->realpath($file);
				$relative_path = substr($file_path, strlen($real_base_path) + 1);

				// Don't want to archive lock file
				if ($relative_path == $lock_relative_path) {
					continue;
				}

				$zip->addFile($file_path, $relative_path);
			}
		}
	}

	/**
	 * Parses content type/field configuration
	 *
	 * @return array
	 *   Array with parsed configuration
	 */
	private function parseFieldConfiguration()
	{
		$field_config = $this->getConfig()->get('field_configuration');
		$lines = explode("\n", $field_config);

		$parsed= array();
		foreach($lines as $line) {
			$split = explode("::", $line);
			$parsed[$split[0]][$split[1]] = $split[2];
		}

		return $parsed;
	}
}
