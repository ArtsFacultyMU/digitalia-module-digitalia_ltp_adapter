<?php

namespace Drupal\digitalia_ltp_adapter_arclib;

use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\File\FileSystem;
use Drupal\digitalia_ltp_adapter\LtpSystemInterface;
use Drupal\digitalia_ltp_adapter\Utils;
use Drupal\digitalia_ltp_adapter\MetadataExtractor;

class LtpSystemArclib implements LtpSystemInterface
{
	private $config;
	private $host;
	private $username;
	private $password;
	private $base_url;
	private $directory;


	public function __construct()
	{
		$this->config = \Drupal::config('digitalia_ltp_adapter_arclib.settings');
		$this->host = $this->config->get('arc_host');
		$this->username = $this->config->get('username');
		$this->password = $this->config->get('password');
		$this->base_url = $this->config->get('base_url');
	}

	public function getBaseUrl()
	{
		return $this->base_url;
	}

	public function getDirectory()
	{
		return $this->directory;
	}

	public function getName()
	{
		return "ARCLib";
	}

	public function setDirectory(String $directory)
	{
		$this->directory = $directory;
	}

	public function writeSIP($entity, Array $metadata, Array $file_uri, Array $dummy_filepaths)
	{
		$token = $this->getAuthorizationToken();
		$utils = new Utils();
		$dirpath = $this->getBaseUrl() . "/" . $this->getDirectory();

		$filesystem = \Drupal::service('file_system');
		$file_repository = \Drupal::service('file.repository');

		if (!$utils->checkAndLock($dirpath)) {
			\Drupal::logger('digitalia_ltp_adapter_arclib')->debug("Couldn't obtain lock for directory '$directory', pre cleanup");
			return;
		}

		$utils->removeFromQueue($this->getDirectory());
		$utils->preExportCleanup($entity, $this->getBaseUrl());

		if (!$utils->checkAndLock($dirpath)) {
			\Drupal::logger('digitalia_ltp_adapter_arclib')->debug("Couldn't obtain lock for directory '$directory', post cleanup");
			return;
		}

		$dir_metadata = $dirpath . "/metadata";
		$dir_objects = $dirpath . "/objects";
		$dummy_filenames = array();

		dpm($metadata);

		// Write correct relative paths to files
		foreach ($metadata as &$section) {
			if ($section["filename"] == "") {
				dpm("Empty filename found!");
				$section["filename"] = "objects/" . $section["export_language"] . ".txt";
				array_push($dummy_filenames, $section["filename"]);
			} else {
				$section["filename"] = "objects/" . $section["filename"];
			}
		}

		dpm($metadata);
		dpm($dummy_filenames);
		dpm($metadata[0]["id"]);

		
		try {
			// write (meta)data
			$filesystem->prepareDirectory($dir_metadata, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS);
			$filesystem->prepareDirectory($dir_objects, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS);

			//$file_repository->writeData($encoded, $dir_metadata . "/metadata.xml" , FileSystemInterface::EXISTS_REPLACE);
			$this->writeArclibMetadata($metadata[0]["id"], $metadata, $dir_metadata . "/metadata.xml");

			if ($file_uri[0] != "") {
				$filesystem->copy($file_uri[0], $dir_objects . "/". $file_uri[1], FileSystemInterface::EXISTS_REPLACE);
			}


			foreach ($dummy_filenames as $_value => $filepath) {
				dpm("writing dummy filename");
				$file_repository->writeData("", $dirpath . "/" . $filepath, FileSystemInterface::EXISTS_REPLACE);
			}


			$utils->removeLock($dirpath);

		} catch (\Exception $e) {
			\Drupal::logger('digitalia_ltp_adapter_arclib')->error($e->getMessage());
			$utils->removeLock($dirpath);
			return;
		}

		$utils->addToQueue(array(
			'directory' => $this->getDirectory(),
			'entity_type' => $entity->getEntityTypeId(),
			'uuid' => $entity->uuid(),
			'fields' => [
				'transfer_uuid' => $this->config->get("transfer_field"),
				'sip_uuid' => $this->config->get("sip_field"),
			],
		));
	}


	/**
	 * Starts ingest in arclib, logs UUID of transfer
	 *
	 * @param $dirpath
	 *   Object directory to be ingested
	 */
	public function startIngest()
	{
		dpm("startIngest: start");
		\Drupal::logger('digitalia_ltp_adapter_arclib')->debug("startIngest: start");

		$transfer_uuid = $this->startTransfer($this->getBaseUrl());

		if ($transfer_uuid) {
			\Drupal::logger('digitalia_ltp_adapter_arclib')->debug("startIngest: transfer with uuid: '$transfer_uuid' started!");
			$this->waitForTransferCompletion($transfer_uuid);
			$sip_uuid = $this->waitForIngestCompletion($transfer_uuid);
			//dpm("sip_uuid: ". $sip_uuid);
			//dpm("startIngest: transfer completed!");
			\Drupal::logger('digitalia_ltp_adapter_arclib')->debug("startIngest: transfer with uuid: '$transfer_uuid' completed!");
			$result = [
				'transfer_uuid' => $transfer_uuid,
				'sip_uuid' => $sip_uuid,
			];

			dpm(print_r($result, TRUE));
			return $result;
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
		$status = "";
		$response = "";

		$client = \Drupal::httpClient();

		while ($status != "COMPLETE") {
			\Drupal::logger('digitalia_ltp_adapter_arclib')->debug("waitForTransferCompletion: loop started");
			sleep(1);
			try {
				$response = $client->request(
					'GET',
					$this->host . '/api/transfer/status/' . $transfer_uuid, [
						'headers' => [
							'Authorization' => 'ApiKey ' . $this->username . ":" . $this->password,
							'Host' => "dirk.localnet",
						]
					]
				);

				$status = json_decode($response->getBody()->getContents(), TRUE)["status"];
				\Drupal::logger('digitalia_ltp_adapter_arclib')->debug("waitForTransferCompletion: status = " . $status);

			} catch (\Exception $e) {
				dpm($e->getMessage());
				return false;
			}

			if ($status == "FAILED" || $status == "REJECTED" || $status == "USER_INPUT") {
				\Drupal::logger('digitalia_ltp_adapter_arclib')->debug("waitForTransferCompletion: status = " . $status);
			}
		}

		\Drupal::logger('digitalia_ltp_adapter_arclib')->debug("waitForTransferCompletion: transfer completed");

	}

	private function waitForIngestCompletion(String $transfer_uuid)
	{
		$sip_uuid = "BACKLOG";
		$client = \Drupal::httpClient();

		while ($sip_uuid == "BACKLOG") {
			$response = $client->request(
				'GET',
				$this->host . '/api/transfer/status/' . $transfer_uuid,
				['headers' => [
					'Authorization' => 'ApiKey ' . $this->username . ":" . $this->password,
					'Host' => "dirk.localnet"
					]
				]
			);

			$sip_uuid = json_decode($response->getBody()->getContents(), TRUE)["sip_uuid"];
		}

		return $sip_uuid;
	}

	/**
	 * Starts transfer of selected directories to Archivematica
	 *
	 * @param String $dirpath
	 *   Directory, which is to be ingested
	 *
	 * @return String
	 *   Transfer UUID
	 */
	private function startTransfer()
	{
		$dirpath = $this->getBaseUrl(). "/" . $this->getDirectory();
		if (!file_exists($dirpath . "/metadata/metadata.xml")) {
			\Drupal::logger('digitalia_ltp_adapter_arclib')->debug("No metadata.xml found. Transfer aborted.");
			return;
		}

		$utils = new Utils();

		\Drupal::logger('digitalia_ltp_adapter_arclib')->debug("Zipping directory $dirpath");
		$zip_file = $utils->zipDirectory($this->getBaseUrl(), $this->getDirectory());

		// delete source directory
		\Drupal::service('file_system')->deleteRecursive($dirpath);


		dpm("Sending request...");

		$client = \Drupal::httpClient();

		$path = "/arclib/drupal/" . $zip_file;
		$transfer_name = transliterator_transliterate('Any-Latin;Latin-ASCII;', $zip_file . "_" . time());

		$ingest_params = array(
			'path' => base64_encode($path),
			'name' => $transfer_name,
			'processing_config' => 'automated',
			'type' => 'zipfile',
		);

		try {
			$response = $client->request(
				'POST',
				$this->host . '/api/v2beta/package', [
					'headers' => [
						'Authorization' => 'ApiKey ' . $this->username . ":" . $this->password,
						'ContentType' => 'application/json',
						],
					'body' => json_encode($ingest_params)
				]
			);

			return json_decode($response->getBody()->getContents(), TRUE)["id"];
		} catch (\Exception $e) {
			dpm($e->getMessage());
			\Drupal::logger('digitalia_ltp_adapter_arclib')->debug("waitForTransferCompletion: " . $e->getMessage());
			return false;
		}
	}

	private function getAuthorizationToken()
	{
		$client = \Drupal::httpClient();

		try {
			$response = $client->request(
				"POST",
				$this->host . "/api/user/login", [
					"headers" => [
						"Authorization" => "Basic " . base64_encode($this->username . ":" . $this->password),
						],
				]
			);

			if (!$response->hasHeader("Bearer")) {
				\Drupal::logger("digitalia_ltp_adapter_arclib")->debug("Failed to get Bearer token.");
				return "";
			}

			return $response->getHeader("Bearer")[0];

			
			// TODO: Should the token be saved to config? Probably not
			//$config = \Drupal::service('config.factory')->getEditable('digitalia_ltp_adapter_arclib.settings');
			//$config->set("token", $response->getHeader("Bearer")[0])->save();
			//dpm($this->config->get("token"));
		} catch (\Exception $e) {
			dpm($e->getMessage());
			\Drupal::logger("digitalia_ltp_adapter_arclib")->debug("getAuthorizationToken: " . $e->getMessage());
			return "";
		}
	}

	private function writeArclibMetadata(String $id, Array $to_encode, String $metadata_file_path)
	{
		dpm($metadata_file_path);
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

