<?php

namespace Mindscreen\FalUpload\Property\TypeConverter;

/***************************************************************
 *  Copyright notice
 *
 *  (c) 2018 mindscreen GmbH
 *
 *  All rights reserved
 *
 *  This script is part of the TYPO3 project. The TYPO3 project is
 *  free software; you can redistribute it and/or modify
 *  it under the terms of the GNU General Public License as published by
 *  the Free Software Foundation; either version 3 of the License, or
 *  (at your option) any later version.
 *
 *  The GNU General Public License can be found at
 *  http://www.gnu.org/copyleft/gpl.html.
 *
 *  This script is distributed in the hope that it will be useful,
 *  but WITHOUT ANY WARRANTY; without even the implied warranty of
 *  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *  GNU General Public License for more details.
 *
 *  This copyright notice MUST APPEAR in all copies of the script!
 ***************************************************************/

use TYPO3\CMS\Core\Resource\File as FalFile;
use TYPO3\CMS\Core\Resource\FileReference as FalFileReference;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Utility\PathUtility;
use TYPO3\CMS\Extbase\Error\Error;
use TYPO3\CMS\Extbase\Property\Exception\TypeConverterException;
use TYPO3\CMS\Extbase\Property\PropertyMappingConfigurationInterface;
use TYPO3\CMS\Extbase\Property\TypeConverter\AbstractTypeConverter;

/**
 * Class UploadedFileReferenceConverter
 */
class UploadedFileReferenceConverter extends AbstractTypeConverter
{

    /**
     * Folder where the file upload should go to (including storage).
     */
    const CONFIGURATION_UPLOAD_FOLDER = 1;

    /**
     * How to handle a upload when the name of the uploaded file conflicts.
     */
    const CONFIGURATION_UPLOAD_CONFLICT_MODE = 2;

    /**
     * Whether to replace an already present resource.
     * Useful for "maxitems = 1" fields and properties
     * with no ObjectStorage annotation.
     */
    const CONFIGURATION_ALLOWED_FILE_EXTENSIONS = 4;

    /**
     * @var string
     */
    protected $defaultUploadFolder = '1:/user_upload/';

    /**
     * One of 'cancel', 'replace', 'changeName'
     *
     * @var string
     */
    protected $defaultConflictMode = 'changeName';

    /**
     * @var array<string>
     */
    protected $sourceTypes = array('array');

    /**
     * @var string
     */
    protected $targetType = 'TYPO3\\CMS\\Extbase\\Domain\\Model\\FileReference';

    /**
     * Take precedence over the available FileReferenceConverter
     *
     * @var integer
     */
    protected $priority = 2;

    /**
     * @var \TYPO3\CMS\Core\Resource\ResourceFactory
     *
     */
    protected $resourceFactory;

    /**
     * @var \TYPO3\CMS\Extbase\Security\Cryptography\HashService
     *
     */
    protected $hashService;

    /**
     * @var \TYPO3\CMS\Extbase\Persistence\Generic\PersistenceManager
     *
     */
    protected $persistenceManager;

    /**
     * @var \TYPO3\CMS\Core\Resource\FileInterface[]
     */
    protected $convertedResources = array();

    /**
     * Actually convert from $source to $targetType, taking into account the fully
     * built $convertedChildProperties and $configuration.
     *
     * @param string|integer $source
     * @param string $targetType
     * @param array $convertedChildProperties
     * @param \TYPO3\CMS\Extbase\Property\PropertyMappingConfigurationInterface $configuration
     * @return \TYPO3\CMS\Extbase\Domain\Model\AbstractFileFolder|Error
     * @throws \TYPO3\CMS\Core\Resource\Exception\FileDoesNotExistException
     * @throws \TYPO3\CMS\Core\Resource\Exception\ResourceDoesNotExistException
     * @throws \TYPO3\CMS\Extbase\Security\Exception\InvalidArgumentForHashGenerationException
     * @throws \TYPO3\CMS\Extbase\Security\Exception\InvalidHashException
     * @api
     */
    public function convertFrom(
        $source,
        $targetType,
        array $convertedChildProperties = array(),
        PropertyMappingConfigurationInterface $configuration = null
    ) {
        if (!isset($source['error']) || $source['error'] === \UPLOAD_ERR_NO_FILE) {
            if (isset($source['submittedFile']['resourcePointer'])) {
                try {
                    $resourcePointer = $this->hashService->validateAndStripHmac($source['submittedFile']['resourcePointer']);
                    if (strpos($resourcePointer, 'file:') === 0) {
                        $fileUid = substr($resourcePointer, 5);
                        return $this->createFileReferenceFromFalFileObject($this->resourceFactory->getFileObject($fileUid));
                    } else {
                        return $this->createFileReferenceFromFalFileReferenceObject($this->resourceFactory->getFileReferenceObject($resourcePointer),
                            $resourcePointer);
                    }
                } catch (\InvalidArgumentException $e) {
                    // Nothing to do. No file is uploaded and resource pointer is invalid. Discard!
                }
            }
            return null;
        }

        if ($source['error'] !== \UPLOAD_ERR_OK) {
            switch ($source['error']) {
                case \UPLOAD_ERR_INI_SIZE:
                case \UPLOAD_ERR_FORM_SIZE:
                case \UPLOAD_ERR_PARTIAL:
                    return new Error($this->getUploadErrorMessage($source['error']), 1264440823);
                default:
                    return new Error('An error occurred while uploading. Please try again or contact the administrator if the problem remains',
                        1340193849);
            }
        }

        if (isset($this->convertedResources[$source['tmp_name']])) {
            return $this->convertedResources[$source['tmp_name']];
        }

        try {
            $resource = $this->importUploadedResource($source, $configuration);
        } catch (\Exception $e) {
            return new Error($e->getMessage(), $e->getCode());
        }

        $this->convertedResources[$source['tmp_name']] = $resource;
        return $resource;
    }

    /**
     * @param FalFile $file
     * @param int $resourcePointer
     * @return \Mindscreen\FalUpload\Domain\Model\FileReference
     */
    protected function createFileReferenceFromFalFileObject(FalFile $file, $resourcePointer = null)
    {
        $fileReference = $this->resourceFactory->createFileReferenceObject(
            array(
                'uid_local' => $file->getUid(),
                'uid_foreign' => uniqid('NEW_'),
                'uid' => uniqid('NEW_'),
                'crop' => null,
            )
        );
        return $this->createFileReferenceFromFalFileReferenceObject($fileReference, $resourcePointer);
    }

    /**
     * @param FalFileReference $falFileReference
     * @param int $resourcePointer
     * @return \Mindscreen\FalUpload\Domain\Model\FileReference
     */
    protected function createFileReferenceFromFalFileReferenceObject(
        FalFileReference $falFileReference,
        $resourcePointer = null
    ) {
        if ($resourcePointer === null) {
            /** @var $fileReference \Mindscreen\FalUpload\Domain\Model\FileReference */
            $fileReference = $this->objectManager->get('TYPO3\\CMS\\Extbase\\Domain\\Model\\FileReference');

        } else {
            $fileReference = $this->persistenceManager->getObjectByIdentifier($resourcePointer,
                'TYPO3\\CMS\\Extbase\\Domain\\Model\\FileReference', false);
        }

        $fileReference->setOriginalResource($falFileReference);

        return $fileReference;
    }

    /**
     * Returns a human-readable message for the given PHP file upload error
     * constant.
     *
     * @param int $errorCode
     * @return string
     */
    protected function getUploadErrorMessage(int $errorCode): string
    {
        switch ($errorCode) {
            case \UPLOAD_ERR_INI_SIZE:
                return 'The uploaded file exceeds the upload_max_filesize directive in php.ini';
            case \UPLOAD_ERR_FORM_SIZE:
                return 'The uploaded file exceeds the MAX_FILE_SIZE directive that was specified in the HTML form';
            case \UPLOAD_ERR_PARTIAL:
                return 'The uploaded file was only partially uploaded';
            case \UPLOAD_ERR_NO_FILE:
                return 'No file was uploaded';
            case \UPLOAD_ERR_NO_TMP_DIR:
                return 'Missing a temporary folder';
            case \UPLOAD_ERR_CANT_WRITE:
                return 'Failed to write file to disk';
            case \UPLOAD_ERR_EXTENSION:
                return 'File upload stopped by extension';
            default:
                return 'Unknown upload error';
        }
    }

    /**
     * Import a resource and respect configuration given for properties
     *
     * @param array $uploadInfo
     * @param PropertyMappingConfigurationInterface $configuration
     * @return \TYPO3\CMS\Extbase\Domain\Model\FileReference
     * @throws TypeConverterException
     * @throws \TYPO3\CMS\Extbase\Security\Exception\InvalidArgumentForHashGenerationException
     * @throws \TYPO3\CMS\Extbase\Security\Exception\InvalidHashException
     */
    protected function importUploadedResource(array $uploadInfo, PropertyMappingConfigurationInterface $configuration)
    {
        if (!GeneralUtility::verifyFilenameAgainstDenyPattern($uploadInfo['name'])) {
            throw new TypeConverterException('Uploading files with PHP file extensions is not allowed!', 1399312430);
        }

        $allowedFileExtensions = $configuration->getConfigurationValue(
            UploadedFileReferenceConverter::class,
            self::CONFIGURATION_ALLOWED_FILE_EXTENSIONS
        );

        if ($allowedFileExtensions !== null) {
            $filePathInfo = PathUtility::pathinfo($uploadInfo['name']);
            if (!GeneralUtility::inList($allowedFileExtensions, strtolower($filePathInfo['extension']))) {
                throw new TypeConverterException('File extension is not allowed!', 1399312430);
            }
        }

        $uploadFolderId = $configuration->getConfigurationValue(
            UploadedFileReferenceConverter::class,
            self::CONFIGURATION_UPLOAD_FOLDER
        ) ?: $this->defaultUploadFolder;
        $conflictMode = $configuration->getConfigurationValue(
            UploadedFileReferenceConverter::class,
            self::CONFIGURATION_UPLOAD_CONFLICT_MODE
        ) ?: $this->defaultConflictMode;

        $uploadFolder = $this->resourceFactory->retrieveFileOrFolderObject($uploadFolderId);
        $uploadedFile = $uploadFolder->addUploadedFile($uploadInfo, $conflictMode);

        $resourcePointer = null;
        if (
            isset($uploadInfo['submittedFile']['resourcePointer']) &&
            strpos($uploadInfo['submittedFile']['resourcePointer'], 'file:') === false
        ) {
            $resourcePointer = $this->hashService->validateAndStripHmac($uploadInfo['submittedFile']['resourcePointer']);
        }

        $fileReferenceModel = $this->createFileReferenceFromFalFileObject($uploadedFile, $resourcePointer);

        return $fileReferenceModel;
    }

    public function injectHashService(\TYPO3\CMS\Extbase\Security\Cryptography\HashService $hashService)
    {
        $this->hashService = $hashService;
    }

    public function injectPersistenceManager(
        \TYPO3\CMS\Extbase\Persistence\Generic\PersistenceManager $persistenceManager
    ) {
        $this->persistenceManager = $persistenceManager;
    }

    public function injectResourceFactory(\TYPO3\CMS\Core\Resource\ResourceFactory $resourceFactory)
    {
        $this->resourceFactory = $resourceFactory;
    }
}