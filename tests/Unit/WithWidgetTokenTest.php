<?php

declare(strict_types=1);

use WorkOS\Resource\WidgetSessionTokenScopes;

it('maps valid scope strings to enum values', function (string $scope, WidgetSessionTokenScopes $expected) {
    $enum = WidgetSessionTokenScopes::from($scope);
    expect($enum)->toBe($expected);
})->with([
    ['widgets:users-table:manage', WidgetSessionTokenScopes::WidgetsUsersTableManage],
    ['widgets:domain-verification:manage', WidgetSessionTokenScopes::WidgetsDomainVerificationManage],
    ['widgets:sso:manage', WidgetSessionTokenScopes::WidgetsSSOManage],
    ['widgets:api-keys:manage', WidgetSessionTokenScopes::WidgetsApiKeysManage],
    ['widgets:dsync:manage', WidgetSessionTokenScopes::WidgetsDsyncManage],
    ['widgets:audit-log-streaming:manage', WidgetSessionTokenScopes::WidgetsAuditLogStreamingManage],
]);

it('rejects invalid scope strings', function () {
    WidgetSessionTokenScopes::from('widgets:settings:read');
})->throws(ValueError::class);

it('rejects empty scope strings', function () {
    WidgetSessionTokenScopes::from('');
})->throws(ValueError::class);
