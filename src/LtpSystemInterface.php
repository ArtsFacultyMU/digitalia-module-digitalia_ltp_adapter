<?php

namespace Drupal\digitalia_ltp_adapter;

interface LtpSystemInterface
{

	public function getName();

	public function getConfig();

	public function setDirectory(String $directory);

	/**
	 * Starts ingest into LTP system
	 */
	public function startIngest();

	/**
	 * Writes SIP to predefined directory
	 *
	 * @param $entity
	 *   Entity from which the metadata was extracted
	 *
	 * @param $metadata
	 *   Array with metadata to be written to SIP
	 *
	 * @param $file_uri
	 *   Array with source path and name of file to be copied to SIP
	 */
	public function writeSIP(EntityInterface $entity, Array $metadata, Array $file_uri);
}
