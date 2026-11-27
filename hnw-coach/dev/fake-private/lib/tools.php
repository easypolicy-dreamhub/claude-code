<?php
// LOCAL TESTING ONLY. Stand-in for private/lib/tools.php: the super admin always passes, everyone else needs the tool ticked.
function can_use_tool($pdo, array $me, string $key): bool
{
    return ($me['role'] ?? '') === 'super_admin' || in_array($key, $me['tools'] ?? [], true);
}
