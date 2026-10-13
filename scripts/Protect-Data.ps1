param([Parameter(Mandatory=$true)][string]$Data)
$ErrorActionPreference='Stop'
$resolvedData=(Resolve-Path -LiteralPath $Data).Path
$workspace=(Resolve-Path -LiteralPath (Split-Path $PSScriptRoot -Parent)).Path
if ($resolvedData -eq [IO.Path]::GetPathRoot($resolvedData) -or $resolvedData -eq $workspace) { throw 'Refusing to change permissions on a filesystem root or source workspace.' }
$ownerSid=[Security.Principal.WindowsIdentity]::GetCurrent().User
$systemSid=New-Object Security.Principal.SecurityIdentifier('S-1-5-18')
function Protect-Entry($entry) {
    if (($entry.Attributes -band [IO.FileAttributes]::ReparsePoint) -ne 0) { throw 'Application data must not contain links or junctions.' }
    if ($entry.PSIsContainer) {
        $acl=New-Object Security.AccessControl.DirectorySecurity
        $inherit=[Security.AccessControl.InheritanceFlags]'ContainerInherit,ObjectInherit'
    } else {
        $acl=New-Object Security.AccessControl.FileSecurity
        $inherit=[Security.AccessControl.InheritanceFlags]::None
    }
    $acl.SetAccessRuleProtection($true,$false)
    foreach ($sid in @($ownerSid,$systemSid)) {
        $rule=New-Object Security.AccessControl.FileSystemAccessRule($sid,'FullControl',$inherit,'None','Allow')
        $acl.AddAccessRule($rule)
    }
    if ($entry.PSIsContainer) { [IO.Directory]::SetAccessControl($entry.FullName,$acl) } else { [IO.File]::SetAccessControl($entry.FullName,$acl) }
}
$root=Get-Item -LiteralPath $resolvedData
Protect-Entry $root
# Do not recurse into reparse points.
$pending=New-Object 'System.Collections.Generic.Stack[string]'
$pending.Push($resolvedData)
while ($pending.Count -gt 0) {
    foreach ($entry in Get-ChildItem -LiteralPath $pending.Pop() -Force) {
        Protect-Entry $entry
        if ($entry.PSIsContainer) { $pending.Push($entry.FullName) }
    }
}
Write-Output 'Application data restricted to current owner and SYSTEM.'

