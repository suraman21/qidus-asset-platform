<?php
declare(strict_types=1);

namespace QidusAsset\Connector;

use PDO;

final class ConnectorFactory
{
    public static function memberConnector(PDO $pdo): MemberConnectorInterface
    {
        $mode = (string)qa_config('integration_mode', 'standalone');
        return match ($mode) {
            'ssms_http' => new SsmsHttpMemberConnector($pdo),
            'ssms_direct' => new SsmsDirectMemberConnector($pdo),
            default => new StandaloneMemberConnector($pdo),
        };
    }
}
