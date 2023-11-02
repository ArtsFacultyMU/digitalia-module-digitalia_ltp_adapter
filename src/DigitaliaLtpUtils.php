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


class DigitaliaLtpUtils
{
	private $directories;
	private $filesystem;
	private $file_repository;
	private $entity_manager;
	private $languages;
	private $config;
	private $content_types_fields;
	private $debug_settings;
	private $METADATA_PATH;

	const Flat = 0;
	const Tree = 1;
	const Single = 2;
	const Separate = 3;

	public function __construct(array $debug_settings = null)
	{
		$this->filesystem = \Drupal::service('file_system');
		$this->file_repository = \Drupal::service('file.repository');
		$this->entity_manager = \Drupal::entityTypeManager();
		$this->languages = \Drupal::languageManager()->getLanguages();
		$this->directories = array();
		$this->config = \Drupal::config('digitalia_ltp_adapter.admin_settings');
		$this->content_types_fields = $this->parseFieldConfiguration();

		$this->debug_settings = $debug_settings;
		if ($debug_settings == null) {
			$this->debug_settings = array(
				'media_toggle' => true,
				'language_toggle' => false,
			);
		}

		// TODO: change to constants
		$this->METADATA_PATH = "metadata/metadata.json";

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


	/**
	 * Gets list of all content types which are to be archived
	 *
	 * @return array
	 *   Array of content types
	 */
	public function getEnabledContentTypes()
	{
		$content_types = array();
		foreach ($this->content_types_fields as $type => $_value) {
			$content_types[$type] = 1;
		}

		return $content_types;
	}

	public function getConfig()
	{
		return $this->config;
	}

	public function getFullEntityUID($entity)
	{
		return $this->config->get('site_name') . "_" . $this->getEntityUID($entity);

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
	private function getEntityUID($entity)
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
		default:
			$prefix = "default";
			break;
		}

		return $prefix . "_" . $entity->id();

	}

	/**
	 * Adds a directory to ingest queue
	 *
	 * @param String $directory
	 *   Name of directory to be ingested
	 */
	public function addToQueue(String $directory)
	{
		$queue = \Drupal::service('queue')->get('digitalia_ltp_adapter_export_queue');
		if (!$queue->createItem($directory)) {
			\Drupal::logger('digitalia_ltp_adapter')->error("Object '" . $directory . "'couldn't be added to queue.");
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
			if ($item->data == $directory) {
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
	}

	/**
	 * Sadly NOT ATOMIC
	 * Checks for lock. If lock is present wait and check again
	 */
	public function checkAndLock(String $directory, int $interval = 2)
	{
		\Drupal::logger('digitalia_ltp_adapter')->debug("checkAndLock: start");
		$path = $this->config->get('base_url') . "/" . $directory;
		$this->filesystem->prepareDirectory($path, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS);

		while ($this->checkLock($directory)) {
			sleep($interval);
		}

		$this->addLock($directory);
		\Drupal::logger('digitalia_ltp_adapter')->debug("checkAndLock: end");
	}

	private function checkLock(String $directory)
	{
		\Drupal::logger('digitalia_ltp_adapter')->debug("checkLock: start, directory: '" . $directory . "'");
		clearstatcache(true, $this->config->get('base_url') . "/" . $directory . "/lock");
		return file_exists($this->config->get('base_url') . "/" . $directory . "/lock");
	}

	private function addLock(String $directory)
	{
		\Drupal::logger('digitalia_ltp_adapter')->debug("addLock: start");
		$this->file_repository->writeData("", $this->config->get('base_url') . "/" . $directory . "/lock", FileSystemInterface::EXISTS_REPLACE);
		\Drupal::logger('digitalia_ltp_adapter')->debug("addLock: end");
	}

	public function removeLock(String $directory)
	{
		\Drupal::logger('digitalia_ltp_adapter')->debug("removeLock: start");
		$this->filesystem->delete($this->config->get('base_url') . "/" . $directory . "/lock");
	}

	/**
	 * Checks wether the entity is published
	 *
	 * @param $entity
	 *  Drupal entity
	 *
	 * @return bool
	 */
	public function getEntityStatus($entity)
	{
		$status = $entity->get("status");
		$published = false;

		// Untranslated entities store bool, translated entities are more complex
		if (is_bool($status)) {
			$published = $status;
		} else {
			$published = $status->getValue()[0]['value'];
		}

		return $published;
	}

	public function updateEntity($entity, bool $delete)
	{
		if (!$this->getConfig()->get('auto_generate_switch')) {
			return;
		}


		if ($this->getEnabledContentTypes()[$entity->bundle()]) {

			if ($this->getEntityStatus($entity)) {
				$this->archiveData($entity, $this::Single, $delete);
			}
		}
	}

	/**
	 * Entrypoint for archiving
	 *
	 * @return array
	 *   Array of object directories prepared for ingest
	 */
	public function archiveData($entity, $export_mode, bool $deleted = false)
	{
		if (!$entity) {
			return $this->directories;
		}

		$type = $entity->getEntityTypeId();
		$this->archiveSourceEntity($entity, $export_mode, $this->config->get('site_name') . "_" . $this->getEntityUID($entity), $deleted);

		return $this->directories;
	}

	/**
	 * Prepares necessary directories, starts metadata harvest and writes metadata
	 *
	 * @param $entity
	 *   Entity which is to be ingested into archivematica
	 *
	 * @param $export_mode
	 *   Sets the object export mode
	 *
	 * @param String $directory
	 *   Name of base object directory
	 *
	 * @param bool $deleted
	 *   True if entity is deleted
	 */
	private function archiveSourceEntity($entity, $export_mode, String $directory, bool $deleted)
	{
		dpm("Preparing data...");

		if ($this->config->get('base_url') == "") {
			dpm("Base URL not set! Aborting.");
			return;
		}


		$this->checkAndLock($directory);
		$this->removeFromQueue($directory);
		$this->preExportCleanup($entity);
		$this->checkAndLock($directory);

		//$to_encode = array('deleted' => $deleted);
		$to_encode = array();
		$current_path = "objects";
		array_push($this->directories, $directory);

		$dir_url = $this->config->get('base_url') . "/" . $directory;
		$dir_metadata = $dir_url . "/metadata";
		$dir_objects = $dir_url . "/objects";

		$this->filesystem->prepareDirectory($dir_metadata, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS);
		$this->filesystem->prepareDirectory($dir_objects, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS);

		$this->harvestMetadata($entity, $current_path, $to_encode, $export_mode, $dir_url, $deleted);

		$encoded = json_encode($to_encode, JSON_UNESCAPED_SLASHES);

		dpm(json_encode($to_encode, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));

		$this->file_repository->writeData($encoded, $dir_url . "/" . $this->METADATA_PATH, FileSystemInterface::EXISTS_REPLACE);

		$this->removeLock($directory);
		$this->addToQueue($directory);

		dpm("Data prepared!");


	}

	/**
	 * Appends metadata of all descendants of a entity
	 *
	 * @param object $entity
	 *   A drupal entity
	 *
	 * @param String $base_path
	 *   Base path of objects from $dir_url
	 *
	 * @param Array $to_encode
	 *   For appending metadata
	 *
	 * @param $export_mode
	 *   Determines export mode
	 *
	 * @param String $dir_url
	 *   URL of object directory, which is ingested to Archivematica
	 */
	private function harvestMetadata($entity, String $base_path, Array &$to_encode, $export_mode, String $dir_url, bool $deleted)
	{
		dpm("Entity type id: " . $entity->getEntityTypeId());
		$type = $entity->getEntityTypeId();

		$current_path = $base_path. "/" . $this->getEntityUID($entity);
		$dir_path = $dir_url . "/". $current_path;
		$filesystem = $this->filesystem->prepareDirectory($dir_path, FileSystemInterface::CREATE_DIRECTORY |
											       FileSystemInterface::MODIFY_PERMISSIONS);

		if ($type == "node") {
			$children = $this->entity_manager->getStorage('node')->loadByProperties(['field_member_of' => $entity->id()]);
			$media = $this->entity_manager->getStorage('media')->loadByProperties(['field_media_of' => $entity->id()]);
			$this->entityExtractMetadata($entity, $current_path, $to_encode, $dir_url, "", $deleted);
		}

		if ($type == "media") {
			$this->harvestMedia($entity, $current_path, $to_encode, $dir_url, $deleted);
		}

		if ($type == "taxonomy_term") {
			dpm("taxonomy_term type harvesting");
			$this->entityExtractMetadata($entity, $current_path, $to_encode, $dir_url, "", $deleted);
		}

		if ($type == "taxonomy_vocabulary") {
			dpm("taxonomy_vocabulary type harvesting");
			$this->entityExtractMetadata($entity, $current_path, $to_encode, $dir_url, "", $deleted);
		}


		if ($export_mode == $this::Separate) {
			foreach($children as $child) {
				$this->archiveData($child, $export_mode);
			}

			foreach($media as $medium) {
				$this->archiveData($medium, $export_mode);
			}
		}

	}

	/**
	 * Extracts metadata from single entity
	 *
	 * @param $entity
	 *   Entity from which metadata is extracted
	 *
	 * @param String $current_path
	 *   Path of entity
	 *
	 * @param Array $to_encode
	 *   Array with metadata
	 *
	 * @param String $dir_url
	 *   URL of object directory, which is ingested to Archivematica
	 *
	 * @param String $filename
	 *   Entity filename, empty when not a file
	 */
	private function entityExtractMetadata($entity, String $current_path, Array &$to_encode, String $dir_url, String $filename, bool $deleted)
	{
		foreach ($this->languages as $lang => $_value) {
			$entity_translated = \Drupal::service('entity.repository')->getTranslationFromContext($entity, $lang);

			if (!$filename) {
				$filepath = $current_path . "/" . $lang . ".txt";
				$this->file_repository->writeData("", $dir_url . '/' . $filepath, FileSystemInterface::EXISTS_REPLACE);
			} else {
				$filepath = $current_path . "/" . $filename;
			}

			$metadata = array('filename' => $filepath, 'export_language' => $lang, 'status' => $this->getEntityStatus($entity_translated), 'deleted' => $deleted);

			$entity_bundle = $entity_translated->bundle();

			// TODO: deal with repeated fields
			// TODO: deal with multiple tokens for single field
			$token_service = \Drupal::token();
			$data = array(
				$entity_translated->getEntityTypeId() => $entity_translated,
			);

			$settings = array(
				'langcode' => $lang,
				'clear' => true,
			);

			foreach ($this->content_types_fields[$entity_bundle] as $name => $value) {
				dpm($token_service->replacePlain($value, $data, $settings));
				$metadata[$name] = $token_service->replacePlain($value, $data, $settings);
			}

			array_push($to_encode, $metadata);
			if ($this->debug_settings['language_toggle']) {
				break;
			}
		}

	}

	/**
	 * Harvests metadata from media entities
	 *
	 * @param $medium
	 *   Media entity to be harvested
	 *
	 * @param String $current_path
	 *   Current path inside staging directory
	 *
	 * @param Array &$to_encode
	 *   Stack variable with harvested metadata
	 *
	 * @param String $dir_url
	 *   URL of staging directory
	 */
	private function harvestMedia($medium, String $current_path, Array &$to_encode, String $dir_url, bool $deleted)
	{
		$fid = $medium->getSource()->getSourceFieldValue($medium);
		$file = File::load($fid);
		$file_url = $file->createFileUrl();
		$file_uri = $file->getFileUri();
		$this->filesystem->copy($file_uri, $dir_url . "/". $current_path . '/' . $file->getFilename(), FileSystemInterface::EXISTS_REPLACE);
		//\Drupal::logger('digitalia_ltp_adapter')->debug();
		dpm($medium->bundle());

		$this->entityExtractMetadata($medium, $current_path, $to_encode, $dir_url, $file->getFilename(), $deleted);
	}

	/**
	 * Starts ingest in archivematica
	 *
	 * @param $directory
	 *   Object directory to be ingested
	 */
	public function startIngest(String $directory)
	{
		dpm("startIngest: start");
		\Drupal::logger('digitalia_ltp_adapter')->debug("startIngest: start");

		$transfer_uuid = $this->startTransfer($directory);

		if ($transfer_uuid) {
			$this->waitForTransferCompletion($transfer_uuid);
			dpm("startIngest: transfer completed!");
			\Drupal::logger('digitalia_ltp_adapter')->debug("startIngest: transfer completed!");
			return;
		}

	}

	/**
	 * Blocks until a transfer is finished, logs unsuccessful transfers
	 *
	 * @param String $transfer_uuid
	 *   uuid to wait for
	 */
	private function waitForTransferCompletion(String $transfer_uuid)
	{
		$am_host = $this->config->get('am_host');
		$username = $this->config->get('api_key_username');
		$password = $this->config->get('api_key_password');
		$status = "";
		$response = "";

		$client = \Drupal::httpClient();

		while ($status != "COMPLETE") {
			\Drupal::logger('digitalia_ltp_adapter')->debug("waitForTransferCompletion: loop started");
			sleep(1);
			try {
				$response = $client->request('GET', $am_host . '/api/transfer/status/' . $transfer_uuid, ['headers' => ['Authorization' => 'ApiKey ' . $username . ":" . $password, 'Host' => "dirk.localnet"]]);
				$status = json_decode($response->getBody()->getContents(), TRUE)["status"];
				\Drupal::logger('digitalia_ltp_adapter')->debug("waitForTransferCompletion: status = " . $status);
			} catch (\Exception $e) {
				dpm($e->getMessage());
				return false;
			}

			if ($status == "FAILED" || $status == "REJECTED" || $status == "USER_INPUT") {
				\Drupal::logger('digitalia_ltp_adapter')->debug("waitForTransferCompletion: status = " . $status);
			}
		}

		\Drupal::logger('digitalia_ltp_adapter')->debug("waitForTransferCompletion: transfer completed");
	}

	/**
	 * Starts transfer of selected directories to Archivematica
	 *
	 * @param String $directory
	 *   Directory, which is to be ingested
	 *
	 * @return String
	 *   Transfer UUID
	 */
	private function startTransfer(String $directory)
	{
		if (!file_exists($this->config->get('base_url') . "/" . $directory . "/metadata/metadata.json")) {
			\Drupal::logger('digitalia_ltp_adapter')->debug("No metadata.json found. Transfer aborted.");
			return;
		}

		dpm("Sending request...");

		$am_host = $this->config->get('am_host');
		$username = $this->config->get('api_key_username');
		$password = $this->config->get('api_key_password');

		$client = \Drupal::httpClient();

		$path = "/archivematica/" . $directory;
		$transfer_name = transliterator_transliterate('Any-Latin;Latin-ASCII;', $directory);

		$ingest_params = array('path' => base64_encode($path), 'name' => $transfer_name, 'processing_config' => 'automated', 'type' => 'standard');
		try {
			$response = $client->request('POST', $am_host . '/api/v2beta/package', ['headers' => ['Authorization' => 'ApiKey ' . $username . ":" . $password, 'ContentType' => 'application/json'], 'body' => json_encode($ingest_params)]);
			return json_decode($response->getBody()->getContents(), TRUE)["id"];
		} catch (\Exception $e) {
			dpm($e->getMessage());
			return false;
		}
		dpm("Request sent");
	}


	/**
	 * Removes all generated files from previous exports
	 *
	 * @param $entity
	 *   Entity to be exported
	 */
	public function preExportCleanup($entity)
	{
		$filename = $this->getFullEntityUID($entity);
		$filepath = $this->config->get('base_url') . "/" . $filename;

		$this->filesystem->deleteRecursive($filepath);
	}


	/**
	 * DEBUG SECTION
	 */

	public function printFieldConfig()
	{
		dpm($this->content_types_fields);
	}

}
