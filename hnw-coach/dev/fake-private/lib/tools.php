<?php
// LOCAL TESTING ONLY. Stand-in for private/lib/tools.php, same rules as the real can_use_tool():
// unknown tool key = no; super admin (is_admin and active) = yes; inactive = no; everyone else needs a tick.
const DEV_TICKS = ['alan' => 11, 'brenda' => 12, 'carol' => 13, 'erin' => 14, 'dora' => 16, 'teamadmin' => 15];
function tool_is_super(array $m): bool { return !empty($m['is_admin']) && !empty($m['is_active']); }
function can_use_tool($pdo, array $member, string $key): bool
{
    if ($key !== 'hnw-coach') return false;
    if (tool_is_super($member)) return true;
    if (empty($member['is_active'])) return false;
    return in_array((int) $member['id'], DEV_TICKS, true);
}
