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
	private $token;


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

	public function getConfig()
	{
		return $this->config;
	}

	public function getName()
	{
		return "ARCLib";
	}

	public function setDirectory(String $directory)
	{
		$this->directory = $directory;
	}

	private function setToken(String $token)
	{
		$this->token = $token;
	}

	/**
	 * {@inheritdoc}
	 */
	public function writeSIP($entity, Array $metadata, Array $file_uri)
	{
		$token = $this->getAuthorizationToken();
		$utils = new Utils();
		$dirpath = $this->getBaseUrl() . "/" . $this->getDirectory();

		$filesystem = \Drupal::service('file_system');
		$file_repository = \Drupal::service('file.repository');

		if (!$utils->checkAndLock($dirpath)) {
			\Drupal::logger('digitalia_ltp_adapter_arclib')->error("Couldn't obtain lock for directory '$dirpath', pre cleanup");
			return;
		}

		$utils->removeFromQueue($this->getDirectory());
		$utils->preExportCleanup($entity, $this->getBaseUrl());

		if (!$utils->checkAndLock($dirpath)) {
			\Drupal::logger('digitalia_ltp_adapter_arclib')->error("Couldn't obtain lock for directory '$dirpath', post cleanup");
			return;
		}

		$dir_metadata = $dirpath . "/metadata";
		$dir_objects = $dirpath . "/objects";

		// Write correct relative path to file
		foreach ($metadata as &$section) {
			if ($section["filename"] != "") {
				$section["filename"] = "objects/" . $section["filename"];
			}
		}

		try {
			// write (meta)data
			$filesystem->prepareDirectory($dir_metadata, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS);
			$filesystem->prepareDirectory($dir_objects, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS);
			$this->writeArclibMetadata($utils->getFullEntityUID($entity), $metadata, $dir_metadata . "/metadata.xml");

			if ($file_uri[0] != "") {
				$filesystem->copy($file_uri[0], $dir_objects . "/". $file_uri[1], FileSystemInterface::EXISTS_REPLACE);
			}

		} catch (\Exception $e) {
			\Drupal::logger('digitalia_ltp_adapter_arclib')->error($e->getMessage());
			$utils->removeLock($dirpath);
			return;
		}
		
		$utils->removeLock($dirpath);

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
	 * {@inheritdoc}
	 */
	public function startIngest()
	{
		$transfer_uuid = $this->startTransfer($this->getBaseUrl());

		if ($transfer_uuid) {
			$external_id = $this->waitForTransferCompletion($transfer_uuid);
			$sip_uuid = $this->getSIPUUID($external_id);

			$result = [
				'transfer_uuid' => $transfer_uuid,
				'sip_uuid' => $sip_uuid,
			];

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

		$state = "";
		$response = "";
		$contents = "";

		$client = \Drupal::httpClient();

		while ($state != "PROCESSED") {
			sleep(1);
			try {
				$response = $client->request(
					'GET',
					$this->host . '/api/batch/' . $transfer_uuid, [
						'headers' => [
							'Authorization' => 'Bearer ' . $this->token,
						]
					]
				);

				$contents = json_decode($response->getBody()->getContents(), TRUE);
				$state = $contents["state"];

				if (!($state == "PROCESSING" || $state == "PROCESSED")) {
					\Drupal::logger('digitalia_ltp_adapter_arclib')->error("waitForTransferCompletion: state = '" . $state . "'. Aborting");
					return false;
				}

			} catch (\Exception $e) {
				return false;
			}
		}

		return $contents["ingestWorkflows"][0]["externalId"];
	}

	private function getSIPUUID(String $external_id)
	{
		$sip_uuid = "";
		$client = \Drupal::httpClient();

		$response = $client->request(
			'GET',
			$this->host . '/api/ingest_workflow/' . $external_id,
			['headers' => [
				'Authorization' => 'Bearer ' . $this->token,
				]
			]
		);

		$contents = json_decode($response->getBody()->getContents(), TRUE);

		$sip_uuid = $contents["ingestWorkflow"]["sip"]["id"];

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
		$zip_file = $utils->zipDirectory($this->getBaseUrl(), $this->getDirectory(), true);

		// delete source directory
		\Drupal::service('file_system')->deleteRecursive($dirpath);

		$client = \Drupal::httpClient();

		$token = $this->getAuthorizationToken();
		$this->setToken($token);

		if ($token == "") {
			\Drupal::logger('digitalia_ltp_adapter_arclib')->error("Can't obtain Authorization token. Transfer aborted.");
			return;
		}

		// get zip hash
		$hash = hash_file("sha512", $this->getBaseUrl() . "/" . $zip_file);
		$producer_id = $this->config->get("producer_profile_id");
		$workflow = $this->config->get("workflow");

		try {
			$response = $client->request(
				'POST',
				$this->host . '/api/batch/process_one', [
					'headers' => [
						'Authorization' => 'Bearer ' . $token,
						],
					'multipart' => [
						[
							"name" => "sipContent",
							"contents" => fopen($this->getBaseUrl() . "/" . $zip_file, "r"),
							"headers" => ["Content-type" => "application/zip"],
						],
						[
							"name" => "workflowConfig",
							"contents" => $workflow,
						],
						[
							"name" => "hashType",
							"contents" => "Sha512",
						],
						[
							"name" => "hashValue",
							"contents" => $hash,
						],
						[
							"name" => "producerProfileExternalId",
							"contents" => $producer_id,
						],
					]
				]
			);

			return $response->getBody()->getContents();

		} catch (\Exception $e) {
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

		} catch (\Exception $e) {
			\Drupal::logger("digitalia_ltp_adapter_arclib")->debug("getAuthorizationToken: " . $e->getMessage());
			return "";
		}
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
					// authorial id for ARCLib, time used for testing purposes.
					// SipMerger fails on same authorial_id even if duplicateSip is removed from workflow,
					// Discussed in issue #146 https://github.com/LIBCAS/ARCLib/issues/146
					$xml->writeElement("authorial_id", $id . "_" . time());
					//$xml->writeElement("authorial_id", $id);
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

