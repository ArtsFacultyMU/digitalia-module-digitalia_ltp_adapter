<?php

namespace Drupal\digitalia_ltp_adapter;

use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\media\Entity\Media;
use Drupal\file\Entity\File;
use Drupal\Core\File\FileSystemInterface;
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

	public function __construct(array $debug_settings = array())
	{
		$this->filesystem = \Drupal::service('file_system');
		$this->file_repository = \Drupal::service('file.repository');
		$this->entity_manager = \Drupal::entityTypeManager();
		$this->languages = \Drupal::languageManager()->getLanguages();
		$this->directories = array();
		$this->config = \Drupal::config('digitalia_ltp_adapter.admin_settings');
		$this->debug_settings = $debug_settings;
		$this->content_types_fields = $this->parseFieldConfiguration();
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



	public function printFieldConfig()
	{
		dpm($this->content_types_fields);
	}

	/**
	 * Entrypoint for archiving
	 *
	 * @return array
	 *   Array of object directories prepared for ingest
	 */
	public function archiveData($node, $export_mode)
	{
		// Using id(), get('title') can contain '/' and other nasty characters
		$this->archiveSourceNode($node, $export_mode, $this->config->get('site_name') . "_" . $node->id());

		return $this->directories;
	}

	/**
	 * Prepares necessary directories, starts metadata harvest and writes metadata
	 *
	 * @param $node
	 *   Node which is to be ingested into archivematica
	 *
	 * @param $export_mode
	 *   Sets the object export mode
	 *
	 * @param String $directory
	 *   Name of base object directory
	 */
	private function archiveSourceNode($node, $export_mode, String $directory)
	{
		dpm("Preparing data...");
		$to_encode = array();
		$current_path = "objects";
		array_push($this->directories, $directory);


		if ($this->config->get('base_url') == "") {
			dpm("Base URL not set! Aborting.");
			return;
		}

		$dir_url = $this->config->get('base_url') . "/" . $directory;
		$dir_metadata = $dir_url . "/metadata";
		$dir_objects = $dir_url . "/objects";

		$this->filesystem->prepareDirectory($dir_metadata, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS);
		$this->filesystem->prepareDirectory($dir_objects, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS);



		$this->harvestMetadata($node, $current_path, $to_encode, $export_mode, $dir_url);

		$encoded = json_encode($to_encode, JSON_UNESCAPED_SLASHES);


		dpm(json_encode($to_encode, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));

		$this->file_repository->writeData($encoded, $dir_url . "/" . $this->METADATA_PATH, FileSystemInterface::EXISTS_REPLACE);

		dpm("Data prepared!");


	}

	/**
	 * Starts ingest in archivematica
	 *
	 * @param $directories
	 *   Object directories to be ingested
	 */
	public function startIngest(array $directories)
	{
		dpm("Starting archivation process...");

		$this->startTransfer($directories);


		dpm("Archivation proces started!");
	}


	/**
	 * Appends metadata of all descendants of a entity
	 *
	 * @param object $node
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
	private function harvestMetadata($node, String $base_path, Array &$to_encode, $export_mode, String $dir_url)
	{
		dpm("Entity type id: " . $node->getEntityTypeId());

		// Using id(), get('title') can contain '/' and other nasty characters
		$current_path = $base_path. "/" . $node->id();
		$dir_path = $dir_url . "/". $current_path;
		$filesystem = $this->filesystem->prepareDirectory($dir_path, FileSystemInterface::CREATE_DIRECTORY |
											       FileSystemInterface::MODIFY_PERMISSIONS);
		$children = $this->entity_manager->getStorage('node')->loadByProperties(['field_member_of' => $node->id()]);
		$media = $this->entity_manager->getStorage('media')->loadByProperties(['field_media_of' => $node->id()]);


		$this->entityExtractMetadata($node, $current_path, $to_encode, $dir_url, "");


		if ($this->debug_settings['media_toggle']) {
			foreach($media as $medium) {
				$this->harvestMedia($medium, $current_path, $to_encode, $dir_url);
			}
		}

		if ($export_mode == $this::Flat) {
			foreach($children as $child) {
				$this->harvestMetadata($child, $base_path, $to_encode, $export_mode, $dir_url);
			}
		}

		if ($export_mode == $this::Tree) {
			foreach($children as $child) {
				$this->harvestMetadata($child, $current_path, $to_encode, $export_mode, $dir_url);
			}
		}

		if ($export_mode == $this::Separate) {
			foreach($children as $child) {
				$this->archiveData($child, $export_mode);
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
	private function entityExtractMetadata($entity, String $current_path, Array &$to_encode, String $dir_url, String $filename)
	{
		foreach ($this->languages as $lang => $_value) {
			$entity_translated = \Drupal::service('entity.repository')->getTranslationFromContext($entity, $lang);

			if (!$filename) {
				$filepath = $current_path . "/" . $lang . ".txt";
				$this->file_repository->writeData("", $dir_url . '/' . $filepath, FileSystemInterface::EXISTS_REPLACE);
			} else {
				$filepath = $current_path . "/" . $filename;
			}

			$metadata = array('filename' => $filepath, 'export_language' => $lang);

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
	private function harvestMedia($medium, String $current_path, Array &$to_encode, String $dir_url)
	{
		$fid = $medium->getSource()->getSourceFieldValue($medium);
		$file = File::load($fid);
		$file_url = $file->createFileUrl();
		$file_uri = $file->getFileUri();
		$this->filesystem->copy($file_uri, $dir_url . "/". $current_path . '/' . $file->getFilename(), FileSystemInterface::EXISTS_REPLACE);
		//\Drupal::logger('digitalia_ltp_adapter')->debug();
		dpm($medium->bundle());

		$this->entityExtractMetadata($medium, $current_path, $to_encode, $dir_url, $file->getFilename());
	}


	/**
	 * Starts transfer of selected directories to Archivematica
	 *
	 * @param Array $directories
	 *   Directories, which are to be ingested
	 */
	private function startTransfer(Array $directories)
	{
		if (!$this->debug_settings['ingest_toggle']) {
			return;
		}

		dpm("Sending request...");

		// TODO: deal with trailing slash in host URL
		$am_host = $this->config->get('am_host');
		$username = $this->config->get('api_key_username');
		$password = $this->config->get('api_key_password');

		$client = \Drupal::httpClient();


		foreach($directories as $directory) {
			$path = "/archivematica/" . $directory;
			$transfer_name = transliterator_transliterate('Any-Latin;Latin-ASCII;', $directory);



			$ingest_params = array('path' => base64_encode($path), 'name' => $transfer_name, 'processing_config' => 'automated', 'type' => 'standard');
			try {
				$response = $client->request('POST', $am_host . '/api/v2beta/package', ['headers' => ['Authorization' => 'ApiKey ' . $username . ":" . $password, 'ContentType' => 'application/json'], 'body' => json_encode($ingest_params)]);
				dpm(json_decode($response->getBody()->getContents(), TRUE));
			} catch (\Exception $e) {
				dpm($e->getMessage());
				return false;
			}
		}
	}

}
