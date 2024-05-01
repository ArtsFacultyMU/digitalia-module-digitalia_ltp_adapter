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
use Drupal\digitalia_ltp_adapter\FileUtils;
//use Drupal\digitalia_ltp_adapter\LtpSystemArchivematica;


class DigitaliaLtpUtils
{
	private $entity_manager;
	private $languages;
	private $content_types_fields;

	public $filesystem;
	public $file_repository;
	public $config;
	public $file_uri;
	public $dummy_filepaths;

	const UPDATE_CREATE = 10;
	const UPDATE_DELETE = 11;

	public function __construct()
	{
		$this->filesystem = \Drupal::service('file_system');
		$this->file_repository = \Drupal::service('file.repository');
		$this->entity_manager = \Drupal::entityTypeManager();
		$this->languages = \Drupal::languageManager()->getLanguages();
		$this->config = \Drupal::config('digitalia_ltp_adapter.admin_settings');
		$this->content_types_fields = $this->parseFieldConfiguration();
		$this->file_uri = ["", ""];
		$this->dummy_filepaths = array();
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
		return $this->getConfig()->get["enabled_ltp_systems"];
	}

	/**
	 * Tries to create/update entity and transfer it into Archivematica
	 *
	 * @param $entity
	 *   Drupal entity
	 *
	 * @param $update_mode
	 *   Indicator of deletion
	 */
	public function updateEntity($entity, $update_mode)
	{
		dpm("preparing Sip before checks");

		if (!$this->getConfig()->get('auto_generate_switch')) {
			return;
		}

		dpm("status check: " . $this->getEntityField($entity, "status"));
		if (!$this->getEntityField($entity, "status")) {
			return;
		}

		if ($this->config->get('base_url') == "") {
			dpm("Base URL not set! Aborting.");
			return;
		}

		dpm("preparing Sip");
		$this->prepareSIP($entity, $this->config->get('site_name') . "_" . $this->getEntityUID($entity), $update_mode);
	}

	/**
	 * Prepares necessary directories, starts metadata harvest and writes metadata
	 *
	 * @param $entity
	 *   Entity which is to be ingested into archivematica
	 *
	 * @param String $directory
	 *   Name of base object directory
	 *
	 * @param $update_mode
	 *   Indicator of deletion
	 */
	private function prepareSIP($entity, String $directory, $update_mode)
	{
		dpm("Preparing data...");

		\Drupal::logger('digitalia_ltp_adapter')->debug("Preparing sip");

		//foreach ($this->config as $conf) {
		//	dpm(print_r($conf, TRUE));
		//}

		$system_service_name = $this->config->get('enabled_ltp_systems');
		dpm("enabled system: '" . $system_service_name . "'");
		$ltp_system = \Drupal::service($system_service_name);

		if (is_null($ltp_system)) {
			dpm("Please enable at least one LTP system.");
			return;
		}

		$ltp_system->setDirectory($directory);

		$dirpath = $ltp_system->getBaseUrl() . "/" . $directory;


		$to_encode = array();
		$current_path = "objects";

		$dir_metadata = $dirpath . "/metadata";
		$dir_objects = $dirpath . "/objects";


		$this->harvestMetadata($entity, $current_path, $to_encode, $dirpath, $update_mode);

		dpm(json_encode($to_encode, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));

		$ltp_system->writeSIP($entity, $to_encode, $this->file_uri, $this->dummy_filepaths);



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
	 * @param String $dir_url
	 *   URL of object directory, which is ingested to Archivematica
	 *
	 * @param $update_mode
	 *   Indicator of deletion
	 */
	private function harvestMetadata($entity, String $base_path, Array &$to_encode, String $dir_url, $update_mode)
	{
		dpm("Entity type id: " . $entity->getEntityTypeId());
		$type = $entity->getEntityTypeId();

		$current_path = $base_path. "/" . $this->getEntityUID($entity);
		$dir_path = $dir_url . "/". $current_path;
		$filesystem = $this->filesystem->prepareDirectory($dir_path, FileSystemInterface::CREATE_DIRECTORY |
											       FileSystemInterface::MODIFY_PERMISSIONS);

		if ($type == "media") {
			$this->harvestMedia($entity);
		}

		$this->entityExtractMetadata($entity, $to_encode, $this->file_uri[1], $update_mode);
	}

	/**
	 * Extracts metadata from single entity
	 *
	 * @param $entity
	 *   Entity from which metadata is extracted
	 *
	 * @param Array $to_encode
	 *   Array with metadata
	 *
	 * @param String $filename
	 *   Entity filename, empty when not a file
	 *
	 * @param $update_mode
	 *   Indicator of deletion
	 */
	private function entityExtractMetadata($entity, Array &$to_encode, String $filename, $update_mode)
	{
		dpm(print_r($this->dummy_filepaths, TRUE));
		foreach ($this->languages as $lang => $_value) {
			dpm("language: " . $lang);
			if (!$entity->hasTranslation($lang)) {
				continue;
			}
			$entity_translated = \Drupal::service('entity.repository')->getTranslationFromContext($entity, $lang);

			$deleted = $update_mode == $this::UPDATE_DELETE;

			$metadata = array(
				'filename' => $filename,
				'id' => $entity->id(),
				'uuid' => $entity->uuid(),
				'entity_type' => $entity->getEntityTypeId(),
				'export_language' => $lang,
				'status' => strval($entity_translated->get("status")->getValue()),
				'deleted' => strval($deleted),
			);

			$entity_bundle = $entity_translated->bundle();

			// TODO: deal with repeated fields
			$token_service = \Drupal::token();
			$data = array(
				$entity_translated->getEntityTypeId() => $entity_translated,
			);

			$settings = array(
				'langcode' => $lang,
				'clear' => true,
			);

			foreach ($this->content_types_fields[$entity_bundle] as $name => $value) {
				//dpm($token_service->replacePlain($value, $data, $settings));
				$metadata[$name] = $token_service->replacePlain($value, $data, $settings);
			}

			array_push($to_encode, $metadata);
		}
		dpm(print_r($this->dummy_filepaths, TRUE));
	}

	/**
	 * Harvests (meta)data from media entities
	 *
	 * @param $medium
	 *   Media entity to be harvested
	 */
	private function harvestMedia($medium)
	{
		$fid = $medium->getSource()->getSourceFieldValue($medium);
		$file = File::load($fid);
		$this->file_uri = [$file->getFileUri(), $file->getFilename()];
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

	private function writeArclibMetadata(String $id, Array $to_encode, String $metadata_file_path)
	{
		$xml = new \XMLWriter();
		$xml->openUri($metadata_file_path);
		$xml->startDocument("1.0", "UTF-8");

		$xml->startElementNS("mets", "mets", "http://www.loc.gov/METS/");
		$xml->writeAttribute("xmlns:xsi", "http://www.w3.org/2001/XMLSchema-instance");
		$xml->writeAttribute("xmlns:xlink", "http://www.w3.org/1999/xlink");
		$xml->writeAttribute("xsi:schemaLocation", "http://www.loc.gov/METS/ http://www.loc.gov/standards/mets/version1121/mets.xsd");
			$xml->startElement("mets:metsHdr");
			$xml->writeAttribute("CREATEDATE", date("c"));
			$xml->writeAttribute("LASTMODDATE", date("c"));
			$xml->endElement();

			$xml->startElement("mets:dmdSec");
			$xml->writeAttribute("ID", "dmdSec_authorial_id");
			$xml->writeAttribute("CREATED", date("c"));
			$xml->writeAttribute("STATUS", "original");
				$xml->startElement("mets:mdWrap");
				$xml->writeAttribute("MDTYPE", "OTHER");
				$xml->writeAttribute("OTHERMDTYPE", "CUSTOM");
					$xml->startElement("mets:xmlData");
					# authorial id for ARCLib
					$xml->writeElement("authorial_id", $id . "_" . time());
					$xml->endElement();
				$xml->endElement();
			$xml->endElement();

			foreach($to_encode as $value => $section) {
				$xml->startElement("mets:dmdSec");
				$xml->writeAttribute("ID", "dmdSec_metadata_" . $value);
				$xml->writeAttribute("CREATED", date("c"));
				$xml->writeAttribute("STATUS", "original");
					$xml->startElement("mets:mdWrap");
					$xml->writeAttribute("MDTYPE", "OTHER");
					$xml->writeAttribute("OTHERMDTYPE", "CUSTOM");
						$xml->startElement("mets:xmlData");
						foreach($section as $name => $value) {
							$xml->writeElement($name, $value);
						}
						$xml->endElement();
					$xml->endElement();
				$xml->endElement();
			}

		$xml->endElement();
		$xml->endDocument();
	}

	public function isPublished($entity)
	{
		
	}


	/**
	 * DEBUG SECTION
	 */

	public function printFieldConfig()
	{
		dpm($this->content_types_fields);
	}

}
