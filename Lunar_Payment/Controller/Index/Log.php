<?php
namespace Lunar\Payment\Controller\Index;

use Magento\Framework\App\Action\Action;
use Magento\Framework\App\Action\Context;
use Magento\Framework\Controller\Result\JsonFactory;

class Log extends Action {

  const MAIN_LOG_DIR = BP . DIRECTORY_SEPARATOR . "var" . DIRECTORY_SEPARATOR . "log";
  const LOGS_DIR_NAME = self::MAIN_LOG_DIR . DIRECTORY_SEPARATOR . "lunar";
  const LOGS_DATE_FORMAT = "Y-m-d-h-i-s";

  public function __construct(JsonFactory $resultJsonFactory, Context $context) {
    $this->resultJsonFactory = $resultJsonFactory;
    parent::__construct($context);
  }

  public function execute() {
    $post = $this->getRequest()->getPostValue();

    if (isset($post["export"])) {
      return $this -> export();
    }

    if (isset($post["hasLogs"])) {
      return $this -> hasLogs();
    }

    if (isset($post["delete"])) {
      return $this -> deleteLogs();
    }

    if (isset($post["writable"])) {
      return $this -> writable();
    }

    return $this -> log();
  }

  private function writable() {
    $response = [
      "dir" => self::MAIN_LOG_DIR,
      "writable" => is_writable(self::MAIN_LOG_DIR),
    ];

    $result = $this->resultJsonFactory->create();
    return $result->setJsonData(json_encode($response));
  }

  private function deleteLogs() {
    $files = glob(self::LOGS_DIR_NAME . DIRECTORY_SEPARATOR . "*.log");
    foreach($files as $file) {
      unlink($file);
    }

    return null;
  }

  private function hasLogs() {
    $files = glob(self::LOGS_DIR_NAME . DIRECTORY_SEPARATOR . "*.log");
    $response = json_encode(array("hasLogs" => count($files) > 0));
    $result = $this->resultJsonFactory->create();
    return $result->setJsonData(count($files) > 0);
  }

  private function export() {
    $filename = self::LOGS_DIR_NAME . DIRECTORY_SEPARATOR . "export.zip";
    $zip = new \ZipArchive();
    $zip->open($filename, \ZipArchive::CREATE);

    $files = glob(self::LOGS_DIR_NAME . DIRECTORY_SEPARATOR . "*.log");
    foreach($files as $file) {
      $zip -> addFile($file, basename($file));
    }

    $zip -> close();

    $content = base64_encode(file_get_contents($filename));
    unlink($filename);

    $result = $this->resultJsonFactory->create();
    return $result->setJsonData($content);
  }

  private function log() {
    $post = $this->getRequest()->getPostValue();

    if (!is_dir(self::LOGS_DIR_NAME)) {
      mkdir(self::LOGS_DIR_NAME);
    }


    $date = date(self::LOGS_DATE_FORMAT, ($post["date"] / 1000));
    $id = $post["context"]["custom"]["quoteId"];
    $filename = self::LOGS_DIR_NAME . DIRECTORY_SEPARATOR . $date . "___" . $id . ".log";

    if (!file_exists($filename)) {
      $separator = "============================================================";
      file_put_contents($filename, $separator . PHP_EOL . json_encode($post) . PHP_EOL . $separator . PHP_EOL . PHP_EOL);
    }

    $newContent = PHP_EOL . date(self::LOGS_DATE_FORMAT) . ": " . $post["message"];
    file_put_contents($filename, $newContent, FILE_APPEND);

    return null;
  }
}
