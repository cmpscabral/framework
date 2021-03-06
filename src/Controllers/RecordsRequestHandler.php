<?php
/**
 * This file is part of the Divergence package.
 *
 * @author Henry Paradiz <henry.paradiz@gmail.com>
 * @copyright 2018 Henry Paradiz <henry.paradiz@gmail.com>
 * @license MIT For the full copyright and license information, please view the LICENSE file that was distributed with this source code.
 *
 * @since 1.0
 * @link https://github.com/Divergence/docs/blob/master/controllers.md
 */
namespace Divergence\Controllers;

use Exception;

use Divergence\Helpers\JSON;
use Divergence\Helpers\JSONP;
use Divergence\Helpers\Util as Util;
use Divergence\IO\Database\MySQL as DB;
use Divergence\Models\ActiveRecord as ActiveRecord;

/**
 * RecordsRequestHandler - A REST API for Divergence ActiveRecord
 *
 * @package Divergence
 * @author  Henry Paradiz <henry.paradiz@gmail.com>
 * @author  Chris Alfano <themightychris@gmail.com>
 */
abstract class RecordsRequestHandler extends RequestHandler
{
    public static $config;

    // configurables
    public static $recordClass;
    public static $accountLevelRead = false;
    public static $accountLevelBrowse = 'Staff';
    public static $accountLevelWrite = 'Staff';
    public static $accountLevelAPI = false;
    public static $browseOrder = false;
    public static $browseConditions = false;
    public static $browseLimitDefault = false;
    public static $editableFields = false;
    public static $searchConditions = false;

    public static $calledClass = __CLASS__;
    public static $responseMode = 'dwoo';

    /**
     * Start of routing for this controller.
     * Methods in this execution path will always respond either as an error or a normal response.
     * Responsible for detecting JSON or JSONP response modes.
     *
     * @return void
     */
    public static function handleRequest()
    {
        // save static class
        static::$calledClass = get_called_class();

        // handle JSON requests
        if (static::peekPath() == 'json') {
            // check access for API response modes
            static::$responseMode = static::shiftPath();
            if (in_array(static::$responseMode, ['json','jsonp'])) {
                if (!static::checkAPIAccess()) {
                    return static::throwAPIUnAuthorizedError();
                }
            }
        }

        return static::handleRecordsRequest();
    }

    public static function handleRecordsRequest($action = false)
    {
        switch ($action ? $action : $action = static::shiftPath()) {
            case 'save':
            {
                return static::handleMultiSaveRequest();
            }

            case 'destroy':
            {
                return static::handleMultiDestroyRequest();
            }

            case 'create':
            {
                return static::handleCreateRequest();
            }

            case '':
            case false:
            {
                return static::handleBrowseRequest();
            }

            default:
            {
                if ($Record = static::getRecordByHandle($action)) {
                    return static::handleRecordRequest($Record);
                } else {
                    return static::throwRecordNotFoundError();
                }
            }
        }
    }

    public static function getRecordByHandle($handle)
    {
        $className = static::$recordClass;

        if (method_exists($className, 'getByHandle')) {
            return $className::getByHandle($handle);
        }
    }

    public static function prepareBrowseConditions($conditions = [])
    {
        if (static::$browseConditions) {
            if (!is_array(static::$browseConditions)) {
                static::$browseConditions = [static::$browseConditions];
            }
            $conditions = array_merge(static::$browseConditions, $conditions);
        }
        return $conditions;
    }

    public static function prepareDefaultBrowseOptions()
    {
        if (empty($_REQUEST['offset']) && is_numeric($_REQUEST['start'])) {
            $_REQUEST['offset'] = $_REQUEST['start'];
        }

        $limit = !empty($_REQUEST['limit']) && is_numeric($_REQUEST['limit']) ? $_REQUEST['limit'] : static::$browseLimitDefault;
        $offset = !empty($_REQUEST['offset']) && is_numeric($_REQUEST['offset']) ? $_REQUEST['offset'] : false;

        $options = [
            'limit' =>  $limit,
            'offset' => $offset,
            'order' => static::$browseOrder,
        ];

        return $options;
    }

    public static function handleBrowseRequest($options = [], $conditions = [], $responseID = null, $responseData = [])
    {
        if (!static::checkBrowseAccess(func_get_args())) {
            return static::throwUnauthorizedError();
        }

        $conditions = static::prepareBrowseConditions($conditions);

        $options = static::prepareDefaultBrowseOptions();

        // process sorter
        if (!empty($_REQUEST['sort'])) {
            $sort = json_decode($_REQUEST['sort'], true);
            if (!$sort || !is_array($sort)) {
                return static::respond('error', [
                    'success' => false,
                    'failed' => [
                        'errors'	=>	'Invalid sorter.',
                    ],
                ]);
            }

            if (is_array($sort)) {
                foreach ($sort as $field) {
                    $options['order'][$field['property']] = $field['direction'];
                }
            }
        }

        // process filter
        if (!empty($_REQUEST['filter'])) {
            $filter = json_decode($_REQUEST['filter'], true);
            if (!$filter || !is_array($filter)) {
                return static::respond('error', [
                    'success' => false,
                    'failed' => [
                        'errors'	=>	'Invalid filter.',
                    ],
                ]);
            }

            foreach ($filter as $field) {
                $conditions[$field['property']] = $field['value'];
            }
        }

        $className = static::$recordClass;

        return static::respond(
            isset($responseID) ? $responseID : static::getTemplateName($className::$pluralNoun),
            array_merge($responseData, [
                'success' => true,
                'data' => $className::getAllByWhere($conditions, $options),
                'conditions' => $conditions,
                'total' => DB::foundRows(),
                'limit' => $options['limit'],
                'offset' => $options['offset'],
            ])
        );
    }


    public static function handleRecordRequest(ActiveRecord $Record, $action = false)
    {
        if (!static::checkReadAccess($Record)) {
            return static::throwUnauthorizedError();
        }

        switch ($action ? $action : $action = static::shiftPath()) {
            case '':
            case false:
            {
                $className = static::$recordClass;

                return static::respond(static::getTemplateName($className::$singularNoun), [
                    'success' => true,
                    'data' => $Record,
                ]);
            }

            case 'edit':
            {
                return static::handleEditRequest($Record);
            }

            case 'delete':
            {
                return static::handleDeleteRequest($Record);
            }

            default:
            {
                return static::onRecordRequestNotHandled($Record, $action);
            }
        }
    }


    public static function prepareResponseModeJSON($methods = [])
    {
        if (static::$responseMode == 'json' && in_array($_SERVER['REQUEST_METHOD'], $methods)) {
            $JSONData = JSON::getRequestData();
            if (is_array($JSONData)) {
                $_REQUEST = $JSONData;
            }
        }
    }

    public static function getDatumRecord($datum)
    {
        $className = static::$recordClass;
        $PrimaryKey = $className::getPrimaryKey();
        if (empty($datum[$PrimaryKey])) {
            $record = new $className::$defaultClass();
            static::onRecordCreated($record, $datum);
        } else {
            if (!$record = $className::getByID($datum[$PrimaryKey])) {
                throw new Exception('Record not found');
            }
        }
        return $record;
    }

    public static function processDatumSave($datum)
    {
        // get record
        $Record = static::getDatumRecord($datum);

        // check write access
        if (!static::checkWriteAccess($Record)) {
            throw new Exception('Write access denied');
        }

        // apply delta
        static::applyRecordDelta($Record, $datum);

        // call template function
        static::onBeforeRecordValidated($Record, $datum);

        // try to save record
        try {
            // call template function
            static::onBeforeRecordSaved($Record, $datum);

            $Record->save();

            // call template function
            static::onRecordSaved($Record, $datum);

            return (!$Record::fieldExists('Class') || get_class($Record) == $Record->Class) ? $Record : $Record->changeClass();
        } catch (Exception $e) {
            throw $e;
        }
    }

    public static function handleMultiSaveRequest()
    {
        $className = static::$recordClass;

        static::prepareResponseModeJSON(['POST','PUT']);

        if ($className::fieldExists(key($_REQUEST['data']))) {
            $_REQUEST['data'] = [$_REQUEST['data']];
        }

        if (empty($_REQUEST['data']) || !is_array($_REQUEST['data'])) {
            return static::respond('error', [
                'success' => false,
                'failed' => [
                    'errors'	=>	'Save expects "data" field as array of records.',
                ],
            ]);
        }

        $results = [];
        $failed = [];

        foreach ($_REQUEST['data'] as $datum) {
            try {
                $results[] = static::processDatumSave($datum);
            } catch (Exception $e) {
                $failed[] = [
                    'record' => $datum,
                    'errors' => $e->getMessage(),
                ];
                continue;
            }
        }


        return static::respond(static::getTemplateName($className::$pluralNoun).'Saved', [
            'success' => count($results) || !count($failed),
            'data' => $results,
            'failed' => $failed,
        ]);
    }

    public static function processDatumDestroy($datum)
    {
        $className = static::$recordClass;
        $PrimaryKey = $className::getPrimaryKey();

        // get record
        if (is_numeric($datum)) {
            $recordID = $datum;
        } elseif (!empty($datum[$PrimaryKey]) && is_numeric($datum[$PrimaryKey])) {
            $recordID = $datum[$PrimaryKey];
        } else {
            throw new Exception($PrimaryKey.' missing');
        }

        if (!$Record = $className::getByField($PrimaryKey, $recordID)) {
            throw new Exception($PrimaryKey.' not found');
        }

        // check write access
        if (!static::checkWriteAccess($Record)) {
            throw new Exception('Write access denied');
        }

        if ($Record->destroy()) {
            return $Record;
        } else {
            throw new Exception('Destroy failed');
        }
    }

    public static function handleMultiDestroyRequest()
    {
        $className = static::$recordClass;

        static::prepareResponseModeJSON(['POST','PUT','DELETE']);

        if ($className::fieldExists(key($_REQUEST['data']))) {
            $_REQUEST['data'] = [$_REQUEST['data']];
        }

        if (empty($_REQUEST['data']) || !is_array($_REQUEST['data'])) {
            return static::respond('error', [
                'success' => false,
                'failed' => [
                    'errors'	=>	'Save expects "data" field as array of records.',
                ],
            ]);
        }

        $results = [];
        $failed = [];

        foreach ($_REQUEST['data'] as $datum) {
            try {
                $results[] = static::processDatumDestroy($datum);
            } catch (Exception $e) {
                $failed[] = [
                    'record' => $datum,
                    'errors' => $e->getMessage(),
                ];
                continue;
            }
        }

        return static::respond(static::getTemplateName($className::$pluralNoun).'Destroyed', [
            'success' => count($results) || !count($failed),
            'data' => $results,
            'failed' => $failed,
        ]);
    }


    public static function handleCreateRequest(ActiveRecord $Record = null)
    {
        // save static class
        static::$calledClass = get_called_class();

        if (!$Record) {
            $className = static::$recordClass;
            $Record = new $className::$defaultClass();
        }

        // call template function
        static::onRecordCreated($Record, $_REQUEST);

        return static::handleEditRequest($Record);
    }

    public static function handleEditRequest(ActiveRecord $Record)
    {
        $className = static::$recordClass;

        if (!static::checkWriteAccess($Record)) {
            return static::throwUnauthorizedError();
        }

        if (in_array($_SERVER['REQUEST_METHOD'], ['POST','PUT'])) {
            if (static::$responseMode == 'json') {
                $_REQUEST = JSON::getRequestData();
                if (is_array($_REQUEST['data'])) {
                    $_REQUEST = $_REQUEST['data'];
                }
            }
            $_REQUEST = $_REQUEST ? $_REQUEST : $_POST;

            // apply delta
            static::applyRecordDelta($Record, $_REQUEST);

            // call template function
            static::onBeforeRecordValidated($Record, $_REQUEST);

            // validate
            if ($Record->validate()) {
                // call template function
                static::onBeforeRecordSaved($Record, $_REQUEST);

                try {
                    // save session
                    $Record->save();
                } catch (Exception $e) {
                    return static::respond('Error', [
                        'success' => false,
                        'failed' => [
                            'errors'	=>	$e->getMessage(),
                        ],
                    ]);
                }

                // call template function
                static::onRecordSaved($Record, $_REQUEST);

                // fire created response
                $responseID = static::getTemplateName($className::$singularNoun).'Saved';
                $responseData = [
                    'success' => true,
                    'data' => $Record,
                ];
                return static::respond($responseID, $responseData);
            }

            // fall through back to form if validation failed
        }

        $responseID = static::getTemplateName($className::$singularNoun).'Edit';
        $responseData = [
            'success' => false,
            'data' => $Record,
        ];

        return static::respond($responseID, $responseData);
    }


    public static function handleDeleteRequest(ActiveRecord $Record)
    {
        $className = static::$recordClass;

        if (!static::checkWriteAccess($Record)) {
            return static::throwUnauthorizedError();
        }

        if ($_SERVER['REQUEST_METHOD'] == 'POST') {
            $data = $Record->data;
            $Record->destroy();

            // call cleanup function after delete
            static::onRecordDeleted($Record, $data);

            // fire created response
            return static::respond(static::getTemplateName($className::$singularNoun).'Deleted', [
                'success' => true,
                'data' => $Record,
            ]);
        }

        return static::respond('confirm', [
            'question' => 'Are you sure you want to delete this '.$className::$singularNoun.'?',
            'data' => $Record,
        ]);
    }


    public static function respond($responseID, $responseData = [], $responseMode = false)
    {
        // default to static property
        if (!$responseMode) {
            $responseMode = static::$responseMode;
        }

        return parent::respond($responseID, $responseData, $responseMode);
    }

    // access control template functions
    public static function checkBrowseAccess($arguments)
    {
        return true;
    }

    public static function checkReadAccess(ActiveRecord $Record)
    {
        return true;
    }

    public static function checkWriteAccess(ActiveRecord $Record)
    {
        return true;
    }

    public static function checkAPIAccess()
    {
        return true;
    }

    public static function throwUnauthorizedError()
    {
        return static::respond('Unauthorized', [
            'success' => false,
            'failed' => [
                'errors'	=>	'Login required.',
            ],
        ]);
    }

    public static function throwAPIUnAuthorizedError()
    {
        return static::respond('Unauthorized', [
            'success' => false,
            'failed' => [
                'errors'	=>	'API access required.',
            ],
        ]);
    }

    public static function throwNotFoundError()
    {
        return static::respond('error', [
            'success' => false,
            'failed' => [
                'errors'	=>	'Record not found.',
            ],
        ]);
    }

    public static function onRecordRequestNotHandled(ActiveRecord $Record, $action)
    {
        return static::respond('error', [
            'success' => false,
            'failed' => [
                'errors'	=>	'Malformed request.',
            ],
        ]);
    }



    public static function getTemplateName($noun)
    {
        return preg_replace_callback('/\s+([a-zA-Z])/', function ($matches) {
            return strtoupper($matches[1]);
        }, $noun);
    }

    public static function applyRecordDelta(ActiveRecord $Record, $data)
    {
        if (is_array(static::$editableFields)) {
            $Record->setFields(array_intersect_key($data, array_flip(static::$editableFields)));
        } else {
            $Record->setFields($data);
        }
    }

    // event template functions
    protected static function onRecordCreated(ActiveRecord $Record, $data)
    {
    }
    protected static function onBeforeRecordValidated(ActiveRecord $Record, $data)
    {
    }
    protected static function onBeforeRecordSaved(ActiveRecord $Record, $data)
    {
    }
    protected static function onRecordDeleted(ActiveRecord $Record, $data)
    {
    }
    protected static function onRecordSaved(ActiveRecord $Record, $data)
    {
    }

    protected static function throwRecordNotFoundError()
    {
        return static::throwNotFoundError();
    }
}
