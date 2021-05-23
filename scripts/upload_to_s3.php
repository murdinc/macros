<?php

require_once(__DIR__.'/../vendor/autoload.php');
require_once(__DIR__.'/../config.php');

use Aws\S3\S3Client;
use Aws\S3\ObjectUploader;
use \Aws\S3\Exception\S3Exception;
use Aws\AwsException;

class Upload_to_S3 {

	function __construct() {
		global $config;
		$files = $this->listFiles($config->new_image_path, 'tl_');

		if ($files) {
			$count = count($files);

			// Get the S3 Client
			$this->s3Client = $this->getS3Client();

			foreach ($files as $i => $file) {
				$current = $i+1;
				echo "Uploading [{$current}/{$count}]: {$file}...\n";
				$url = $this->uploadFile($config->new_image_path, $file);

				if ($url) {
					//Delete file locally
					$this->deleteFile($config->new_image_path, $file);

				} else {
					// Move file to HD for storage until we can upload
					rename ($config->new_image_path.'/'.$file, $config->backup_image_path.'/'.$file);
				}

				echo "\n";
			}

			// Upload any files on the hard drive
				$files = $this->listFiles($config->backup_image_path, 'tl_');
				if ($files) {

					$count = count($files);

					foreach ($files as $i => $file) {
						$current = $i+1;
						echo "Uploading Backup Image [{$current}/{$count}]: {$file}...\n";
						$url = $this->uploadFile($config->backup_image_path, $file);

						if ($url) {
							//Delete file locally
							$this->deleteFile($config->backup_image_path, $file);
						}
						echo "\n";
					}
				}
		} else {
			echo "No file found to upload!\n";
		}
	}

	function getS3Client() {
		global $config;
		return new S3Client([
			'version'		=> 'latest',
			'region'		=> "us-east-1",
			'credentials'	=> [
				'key'		=> $config->aws_key,
				'secret'	=> $config->aws_secret,
			],
		]);
	}

	function listFiles($path, $prefix = '') {
		global $config;

		$files = scandir($path);
		foreach ($files as $i => $file) {
			$info = pathinfo($file);
			// ignore non-jpg files
			if ($info['extension'] != 'jpg') {
				unset($files[$i]);
			}
			// ignore thumbnails
			if (substr($info['filename'], -3) == '.th') {
				unset($files[$i]);
			}
			// ignore files that dont match prefix (if supplied)
			if ($prefix && !(substr($file, 0, strlen($prefix)) == $prefix)) {
				unset($files[$i]);
			}
		}
		return array_values($files);
	}

	function deleteFile($path, $file) {
		if ($path && $file) {
			echo "	- Deleting File: [$file]\n";
			return unlink($path.'/'.$file);
		}
		return false;
	}

	function parseFileName($file) {
		// tl_0015_0010_20210523_212734.jpg
		$regex = '~^tl_[0-9]{4}_[0-9]{4}_([0-9]{8}_[0-9]{6})\.jpg$~';
		preg_match($regex, $file, $matches);

		if (!$matches[1]) {
			return false;
		}

		$date = DateTime::createFromFormat('Ymd_His', $matches[1]);
		$date->setTimeZone(new DateTimeZone('PDT'));

		$fileNameInfo = [
			'date' => $date->format('Y/m/d'),
			'timestamp' => $date->getTimestamp(),
			'human' => $date->format('d/m/Y h:i:s T'),
		];

		return $fileNameInfo;
	}

	function uploadFile($path, $file) {
		global $config;

		$filePath = $path . "/" . $file;

		$fileNameInfo = $this->parseFileName($file);

		if (!$fileNameInfo) {
			// TODO THROW ERROR AND ALERT
			return false;
		}

		// get filename
		$dateFolder = $fileNameInfo['date'];
		$timestamp = $fileNameInfo['timestamp'];
		$key = "{$config->s3_upload_folder}/{$dateFolder}/{$timestamp}.jpg";

		echo "	- S3 Key: [$key]\n";

		// Using stream instead of file path
		$source = fopen($filePath, 'rb');

		$uploader = new ObjectUploader(
			$this->s3Client,
			$config->s3_bucket,
			$key,
			$source
		);

		$objectUrl = '';
		do {
			try {
				$result = $uploader->upload();
				if ($result["@metadata"]["statusCode"] == '200') {
					$objectUrl = $result["ObjectURL"];
				}

			} catch (MultipartUploadException $e) {
				rewind($source);
				$uploader = new MultipartUploader($this->s3Client, $source, [
					'state' => $e->getState(),
				]);
			}
		} while (!isset($result));

		fclose($source);

		if (!$objectUrl) {
			// TODO THROW ERROR AND ALERT
			return false;
		}

		return $objectUrl;
	}

}

$upload = new Upload_to_S3();