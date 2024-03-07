<?php

namespace Lunar\Payment\Controller\Index;


use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Filesystem\Driver\File;

class Log implements \Magento\Framework\App\ActionInterface
{
    private $request;
    private $resultJsonFactory;
    private $fileDriver;

    private const MAIN_LOG_DIR = BP . DIRECTORY_SEPARATOR . "var" . DIRECTORY_SEPARATOR . "log";
    private const LOGS_DATE_FORMAT = "Y-m-d__h-i-s";

    private string $LOGS_DIR_NAME = self::MAIN_LOG_DIR . DIRECTORY_SEPARATOR . "lunar";

    public function __construct(
        RequestInterface $request,
        JsonFactory $resultJsonFactory,
        File $fileDriver
    ) {
      $this->request = $request;
        $this->resultJsonFactory = $resultJsonFactory;
        $this->fileDriver = $fileDriver;
    }

    /**
     *
     */
    public function execute()
    {
        $post = $this->request->getParams();

        /** Specific folder name for each payment method */
        $methodCode = !empty($post['method_code']) ? $post['method_code'] : 'lunar';
        $this->LOGS_DIR_NAME = str_replace('lunar', $methodCode, $this->LOGS_DIR_NAME);

        if (isset($post["export"])) {
            return $this->export();
        }

        if (isset($post["hasLogs"])) {
            return $this->hasLogs();
        }

        if (isset($post["delete"])) {
            return $this->deleteLogs();
        }

        if (isset($post["writable"])) {
            return $this->writable();
        }

        return $this->log();
    }

    /**
     *
     */
    private function writable()
    {
        $response = [
            "dir" => self::MAIN_LOG_DIR,
            "writable" => $this->fileDriver->isWritable(self::MAIN_LOG_DIR),
        ];

        $result = $this->resultJsonFactory->create();
        return $result->setJsonData(json_encode($response));
    }

    /**
     *
     */
    private function deleteLogs()
    {
        $files = \Magento\Framework\Filesystem\Glob::glob($this->LOGS_DIR_NAME . DIRECTORY_SEPARATOR . "*.log");
        foreach ($files as $file) {
            unlink($file);
        }

        return null;
    }

    /**
     *
     */
    private function hasLogs()
    {
        $files = \Magento\Framework\Filesystem\Glob::glob($this->LOGS_DIR_NAME . DIRECTORY_SEPARATOR . "*.log");
        $response = json_encode(array("hasLogs" => count($files) > 0));
        $result = $this->resultJsonFactory->create();
        return $result->setJsonData(count($files) > 0);
    }

    /**
     *
     */
    private function export()
    {
        $filename = $this->LOGS_DIR_NAME . DIRECTORY_SEPARATOR . "export.zip";
        $zip = new \ZipArchive();
        $zip->open($filename, \ZipArchive::CREATE);

        $files = \Magento\Framework\Filesystem\Glob::glob($this->LOGS_DIR_NAME . DIRECTORY_SEPARATOR . "*.log");
        foreach ($files as $file) {
            $zip->addFile($file, basename($file));
        }

        $zip->close();

        $content = base64_encode(file_get_contents($filename));
        $this->fileDriver->deleteFile($filename);

        $result = $this->resultJsonFactory->create();
        return $result->setJsonData($content);
    }

    /**
     *
     */
    private function log()
    {
        $post = $this->request->getParams();

        if (!$this->fileDriver->isDirectory($this->LOGS_DIR_NAME)) {
            $this->fileDriver->createDirectory($this->LOGS_DIR_NAME);
        }

        $date = date(self::LOGS_DATE_FORMAT, (int)($post["date"] / 1000));
        $id = $post["context"]["custom"]["quoteId"];
        $filename = $this->LOGS_DIR_NAME . DIRECTORY_SEPARATOR . $date . "___" . $id . ".log";

        if (!$this->fileDriver->isExists($filename)) {
            $separator = "============================================================";
            $contents = $separator . PHP_EOL . json_encode($post) . PHP_EOL . $separator . PHP_EOL . PHP_EOL;
            $this->fileDriver->filePutContents($filename, $contents);
        }

        $newContent = PHP_EOL . date(self::LOGS_DATE_FORMAT) . ": " . $post["message"];
        $this->fileDriver->filePutContents($filename, $newContent, FILE_APPEND);

        return $this->resultJsonFactory->create()->setJsonData('{"success": true}');
    }
}
