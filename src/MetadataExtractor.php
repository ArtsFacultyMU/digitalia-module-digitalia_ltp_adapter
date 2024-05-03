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
use Drupal\digitalia_ltp_adapter\Utils;


class MetadataExtractor
{
	private $entity_manager;
	private $languages;
	private $utils;

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
		$this->file_uri = ["", ""];
		$this->dummy_filepaths = array();
		$this->utils = new Utils();
	}

	public function getConfig()
	{
		return $this->config;
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

		dpm("status check: " . $this->utils->getEntityField($entity, "status"));
		if (!$this->utils->getEntityField($entity, "status")) {
			return;
		}

		dpm("preparing Sip");
		$this->prepareMetadata($entity, $this->config->get('site_name') . "_" . $this->utils->getEntityUID($entity), $update_mode);
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
	private function prepareMetadata($entity, String $directory, $update_mode)
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

		$this->harvestMetadata($entity, $to_encode, $dirpath, $update_mode);

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
	private function harvestMetadata($entity, Array &$to_encode, String $dir_url, $update_mode)
	{
		dpm("Entity type id: " . $entity->getEntityTypeId());
		$type = $entity->getEntityTypeId();

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

			foreach ($this->utils->getContentTypesFields()[$entity_bundle] as $name => $value) {
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

}
