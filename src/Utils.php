<?php

namespace Drupal\digitalia_ltp_adapter;

use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\media\Entity\Media;
use Drupal\file\Entity\File;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\File\FileSystem;
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

	/**
	 * Sadly NOT ATOMIC
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

		while ($this->checkLock($dirpath)) {
			sleep($interval);
			$total_wait += $interval;
			if ($total_wait >= $timeout) {
				return false;
			}
		}

		$this->addLock($dirpath);
		//\Drupal::logger('digitalia_ltp_adapter')->debug("checkAndLock: end");
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

	private function checkLock(String $dirpath)
	{
		clearstatcache(true, $dirpath . "/lock");
		return file_exists($dirpath . "/lock");
	}

	// TODO: do it properly, not with EXISTS_REPLACE, we need to go deeper?
	private function addLock(String $dirpath)
	{
		$this->file_repository->writeData("", $dirpath . "/lock", FileSystemInterface::EXISTS_REPLACE);
	}

	/**
	 * Creates filename/directory friendly uid for entities
	 *
	 * @param $entity
	 *   Entity for which to generate uid
	 *
	 * @return
	 *   String uid
	 */
	public function getEntityUID($entity)
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
			$prefix = "default";
			break;
		}

		return $prefix . "_" . $entity->id();
	}


	/**
	 * Gets full entity uid for export directory structure (base object directory)
	 *
	 * @param $entity
	 *   Entity for which to generate full uid
	 *
	 * @return
	 *   String full uid
	 */
	public function getFullEntityUID($entity)
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
	 *   Array with name, entity type and entity uuid
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
	 * Removes directory from ingest queue
	 *
	 * @param String $directory
	 *   Name of directory to remove from ingest queue
	 *
	 * @param bool $all
	 *   Set to true to delete all intstances of $directory from queue
	 */
	public function removeFromQueue(String $directory, bool $all = false)
	{
		\Drupal::logger('digitalia_ltp_adapter')->debug("removeFromQueue: start");
		$queue = \Drupal::service('queue')->get('digitalia_ltp_adapter_export_queue');
		$items = array();
		while ($item = $queue->claimItem()) {
			\Drupal::logger('digitalia_ltp_adapter')->debug("removeFromQueue: direcotry = " . $directory . "; item = " . $item->data);
			if ($item->data["directory"] == $directory) {
				\Drupal::logger('digitalia_ltp_adapter')->debug("removeFromQueue: item found");
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

		\Drupal::logger('digitalia_ltp_adapter')->debug("removeFromQueue: end");
	}

	/**
	 * Gets entity field in default language
	 *
	 * @param $entity
	 *  Drupal entity
	 *
	 */
	public function getEntityField($entity, String $field)
	{
		// TODO: decide if language variants of an entity are separate entities
		dpm("field: " . $field);
		dpm("entity type: " . $entity->bundle());

		try {
			$value = $entity->get($field);
		} catch (\Exception $e) {
			dpm($e->getMessage());
			return;
		}

		// Use default language for processing
		//$langcode = \Drupal::DefaultLanguageItem->getDefaultLangcode($entity);
		//$langcode = "en";
		//$translated = $entity->getTranslation($langcode);
		//$translated = \Drupal::service('entity.repository')->getTranslationFromContext($entity, $langcode);
		// Untranslated entities store bool, translated entities are more complex

		$value = $entity->get($field)->getValue();

		if (is_array($value)) {
			return $entity->get($field)->getValue()[0]["value"];
		}

		return $value;
		//return $translated->get($field)->getValue();
	}

	public function getEnabledLtpSystem()
	{
		return $this->config->get["enabled_ltp_systems"];
	}

	/**
	 * @param $pathdir
	 *   Path, where the soon to be archived directory lies (with trailng slash)
	 *
	 * @param $directory
	 *   Directory to be archived
	 *
	 */
	public function zipDirectory(String $base_url, String $directory)
	{
		$zip_file = $directory . ".zip";
		$filesystem = \Drupal::service('file_system');

		// zip file must exist
		fopen($filesystem->realpath($base_url . "/" . $zip_file), "w");
		$zip = \Drupal::service('plugin.manager.archiver')->getInstance(['filepath' => $base_url . "/" . $zip_file])->getArchive();

		$files = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator($base_url . "/" . $directory),
			\RecursiveIteratorIterator::LEAVES_ONLY
		);

		// Add root directory, otherwise ARCLib fails to validate zip (possibly resolved by #143)
		$zip->addEmptyDir($directory);

		foreach ($files as $file) {
			if (!$file->isDir()) {
				$file_path = $filesystem->realpath($file);
				$relative_path = substr($file_path, strlen($filesystem->realpath($base_url . "/")) + 1);

				// Don't want to archive lock file
				if ($relative_path == $directory . "/lock") {
					continue;
				}

				$zip->addFile($file_path, $relative_path);
			}
		}

		$zip->close();

		return $directory . ".zip";
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
		dpm("getting content types: " . print_r($content_types, TRUE));

		return $content_types;
	}

	public function getConfig()
	{
		return $this->config;
	}

	/**
	 * Parses content type/field configuration
	 *
	 * @return array
	 *   Array with parsed configuration
	 */
	private function parseFieldConfiguration()
	{
		$field_config = $this->config->get('field_configuration');
		$lines = explode("\n", $field_config);

		$parsed= array();
		foreach($lines as $line) {
			$split = explode("::", $line);
			$parsed[$split[0]][$split[1]] = $split[2];
		}

		return $parsed;
	}

	public function getContentTypesFields()
	{
		return $this->content_types_fields;
	}

}
