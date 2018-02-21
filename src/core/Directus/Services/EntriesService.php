<?php

namespace Directus\Services;

use Directus\Database\TableGateway\DirectusUsersTableGateway;
use Directus\Database\TableGateway\RelationalTableGateway as TableGateway;
use Directus\Exception\BadRequestException;
use Directus\Util\ArrayUtils;

class EntriesService extends AbstractService
{
    public function createEntry($table, $payload, $params = [])
    {
        $dbConnection = $this->container->get('database');
        $acl = $this->container->get('acl');
        $id = null;
        $params['table_name'] = $table;
        $TableGateway = new TableGateway($table, $dbConnection, $acl);
        $payloadCount = count($payload);
        $hasPrimaryKeyData = ArrayUtils::has($payload, $TableGateway->primaryKeyFieldName);

        if ($payloadCount === 0 || ($hasPrimaryKeyData && count($payload) === 1)) {
            throw new BadRequestException(__t('request_payload_empty'));
        }

        $activityLoggingEnabled = !(isset($params['skip_activity_log']) && (1 == $params['skip_activity_log']));
        $activityMode = $activityLoggingEnabled ? TableGateway::ACTIVITY_ENTRY_MODE_PARENT : TableGateway::ACTIVITY_ENTRY_MODE_DISABLED;

        $newRecord = $TableGateway->updateRecord($payload, $activityMode);

        return $newRecord;
    }
}
