<#
.SYNOPSIS
    TEST TOOL for BLOOM: forces ONE customer account to a fraud score of 50 or
    more and restricts it, so you can test the appeal flow end to end.

.DESCRIPTION
    What it does to the customer you name (by email):
      1. Adds real fraud activity codes, with the same reason texts the system
         writes by itself (banned device, disposable phone, rapid checkouts,
         spending above their usual range, VPN). Together they score 53.
      2. Recomputes riskScore / riskLevel with the SAME tier points and groups
         as includes/fraud_activity.php (so the dashboard shows what it would
         show for a real case). Level becomes "critical" (banned device).
      3. Restricts the account for 30 days (isRestricted + restrictedUntil).
         The restricted customer then cannot order with ANY payment method and
         sees the "Appeal this restriction" link.
      4. Sets lastFraudActivityAt = now, so an older "False Alarm" review no
         longer counts.
      5. Deletes any old appeal for that customer (so you can send a fresh one).
         Use -KeepAppeal to leave it.
      6. RECORDS it: one admin_actions document (action: test_force_restriction)
         in Firestore, and one line in restrict-test-log.csv next to this script.
      7. Saves the account's previous values in restrict-backup-<uid>.json so
         -Undo can put everything back exactly as it was.

    It refuses to touch: a blacklisted (blocked) account, and any account that
    belongs to an admin / super admin.

    It needs a Firebase SERVICE ACCOUNT key (the same kind your PHP server
    uses). That key can change your whole database. Keep it private, never
    commit it, and delete it from your computer when you are done testing.

.PARAMETER Email
    The customer's email address (the one on their BLOOM account).

.PARAMETER ServiceAccountPath
    Path to the service account .json key. Defaults to the
    GOOGLE_APPLICATION_CREDENTIALS environment variable, then to
    .\serviceAccountKey.json

.PARAMETER Undo
    Restores the account to how it was before this script touched it.

.PARAMETER KeepAppeal
    Do not delete an existing appeal for this customer.

.PARAMETER Force
    Skip the "type yes to continue" question.

.PARAMETER DryRun
    Show what WOULD be written, change nothing.

.EXAMPLE
    .\Test-Restrict.ps1 -Email customer@example.com
    .\Test-Restrict.ps1 -Email customer@example.com -Undo
#>
[CmdletBinding()]
param(
    [Parameter(Mandatory = $true)][string]$Email,
    [string]$ServiceAccountPath,
    [switch]$Undo,
    [switch]$KeepAppeal,
    [switch]$Force,
    [switch]$DryRun
)

$ErrorActionPreference = 'Stop'

# Older Windows PowerShell may not use TLS 1.2 by default; Google requires it.
try { [Net.ServicePointManager]::SecurityProtocol = [Net.ServicePointManager]::SecurityProtocol -bor [Net.SecurityProtocolType]::Tls12 } catch { }

# ---------------------------------------------------------------------------
# 1. SETTINGS: must stay the same as includes/fraud_activity.php
# ---------------------------------------------------------------------------
$TierPoints = @{ very_strong = 15; strong = 10; moderate_strong = 8; supporting = 6; contextual = 3 }

# code -> tier + group (one score per group: the best code in the group counts)
$CodeRules = @{
    banned_device            = @{ tier = 'very_strong';     group = 'device' }
    price_mismatch           = @{ tier = 'very_strong';     group = 'price' }
    phone_disposable_voip    = @{ tier = 'strong';          group = 'phone_quality' }
    velocity_rapid           = @{ tier = 'strong';          group = 'velocity' }
    history_amount_deviation = @{ tier = 'strong';          group = 'spending' }
    new_account_high_value   = @{ tier = 'strong';          group = 'spending' }
    ip_tor_abuse             = @{ tier = 'moderate_strong'; group = 'network' }
    ip_vpn_proxy             = @{ tier = 'moderate_strong'; group = 'network' }
    geo_mismatch             = @{ tier = 'moderate_strong'; group = 'location' }
    velocity_repeat          = @{ tier = 'supporting';      group = 'velocity' }
    phone_reuse              = @{ tier = 'supporting';      group = 'phone_link' }
    address_reuse            = @{ tier = 'supporting';      group = 'address_link' }
    email_risk_signup        = @{ tier = 'supporting';      group = 'email' }
}
$ScoreCap = 100
$HardEvidenceCodes = @('banned_device')
$StudyCategories = @('account_takeover', 'payment_fraud', 'fake_transaction', 'malicious_return')
$RestrictionDays = 30

# The fake fraud activity added to the account. Reason texts are the ones the
# system writes itself, so the dashboard classifies them correctly.
# 15 + 10 + 10 + 10 + 8 = 53 points (needs 50 to turn Cash on Delivery off).
$Activities = @(
    @{ category = 'fake_transaction'; code = 'banned_device';            reason = 'Order placed from a previously banned device' },
    @{ category = 'fake_transaction'; code = 'phone_disposable_voip';    reason = 'Disposable/VOIP phone number used at checkout' },
    @{ category = 'fake_transaction'; code = 'velocity_rapid';           reason = 'Multiple rapid checkouts flagged (2 prior orders in under 5 minutes)' },
    @{ category = 'payment_fraud';    code = 'history_amount_deviation'; reason = "Order total P5,000.00 is above this customer's usual spending range (average P800.00 + 2 standard deviations = P2,000.00, from 4 orders in the last 60 days)" },
    @{ category = 'account_takeover'; code = 'ip_vpn_proxy';             reason = 'VPN/Proxy detected' }
)
$TestFlag = 'TEST: restriction forced by Test-Restrict.ps1 (not a real fraud case)'

# The customer fields this script is allowed to change (and that -Undo restores).
$TouchedFields = @('isRestricted', 'restrictedUntil', 'riskScore', 'riskLevel', 'fraudCodes', 'fraudCategories', 'fraudFlags', 'lastFraudActivityAt')

$ScriptDir = if ($PSScriptRoot) { $PSScriptRoot } else { (Get-Location).Path }

# ---------------------------------------------------------------------------
# 2. SMALL HELPERS
# ---------------------------------------------------------------------------
function Write-Step([string]$text) { Write-Host "  - $text" -ForegroundColor Gray }

function ConvertTo-Base64Url([byte[]]$bytes) {
    return [Convert]::ToBase64String($bytes).TrimEnd('=').Replace('+', '-').Replace('/', '_')
}

# Firestore REST value builders
function Fs-String([string]$v)  { return @{ stringValue = $v } }
function Fs-Bool([bool]$v)      { return @{ booleanValue = $v } }
function Fs-Int([int]$v)        { return @{ integerValue = "$v" } }
function Fs-Time([datetime]$v)  { return @{ timestampValue = $v.ToUniversalTime().ToString("yyyy-MM-dd'T'HH:mm:ss'Z'") } }
function Fs-List([object[]]$items) {
    $values = @()
    foreach ($i in $items) { $values += , (Fs-String ([string]$i)) }
    return @{ arrayValue = @{ values = $values } }
}

# Reads a string list out of a Firestore field (or returns an empty list).
function Read-StringList($field) {
    $out = @()
    if ($field -and $field.arrayValue -and $field.arrayValue.values) {
        foreach ($v in $field.arrayValue.values) {
            if ($v.stringValue) { $out += [string]$v.stringValue }
        }
    }
    return , $out
}

function Merge-Unique([object[]]$existing, [object[]]$new) {
    $result = New-Object System.Collections.ArrayList
    foreach ($item in (@($existing) + @($new))) {
        if ($item -and -not $result.Contains($item)) { [void]$result.Add($item) }
    }
    return , @($result)
}

# Same math as bloom_fraud_score(): the best code per group counts, then cap.
function Get-RiskScore([object[]]$codes) {
    $best = @{}
    foreach ($code in $codes) {
        if (-not $CodeRules.ContainsKey($code)) { continue }
        $rule = $CodeRules[$code]
        $points = $TierPoints[$rule.tier]
        if (-not $best.ContainsKey($rule.group) -or $points -gt $best[$rule.group]) { $best[$rule.group] = $points }
    }
    $total = 0
    foreach ($v in $best.Values) { $total += $v }
    return [Math]::Min($total, $ScoreCap)
}

# Same rules as bloom_fraud_risk_level().
function Get-RiskLevel([object[]]$categories, [object[]]$codes) {
    foreach ($code in $codes) { if ($HardEvidenceCodes -contains $code) { return 'critical' } }
    $distinct = @($categories | Where-Object { $StudyCategories -contains $_ } | Select-Object -Unique).Count
    if ($distinct -ge 2) { return 'high' }
    if ($distinct -eq 1) { return 'medium' }
    return 'low'
}

# ---------------------------------------------------------------------------
# 3. GOOGLE SIGN-IN WITH THE SERVICE ACCOUNT KEY (no extra tools needed)
# ---------------------------------------------------------------------------
function Get-AccessToken($sa) {
    # Seconds since 1970 (UTC), the clock format Google's sign-in expects.
    $now = [int][DateTimeOffset]::UtcNow.ToUnixTimeSeconds()
    $header = ConvertTo-Base64Url ([Text.Encoding]::UTF8.GetBytes((@{ alg = 'RS256'; typ = 'JWT' } | ConvertTo-Json -Compress)))
    $claims = ConvertTo-Base64Url ([Text.Encoding]::UTF8.GetBytes((@{
        iss   = $sa.client_email
        scope = 'https://www.googleapis.com/auth/datastore'
        aud   = 'https://oauth2.googleapis.com/token'
        iat   = $now
        exp   = $now + 3000
    } | ConvertTo-Json -Compress)))
    $unsigned = "$header.$claims"
    $dataBytes = [Text.Encoding]::UTF8.GetBytes($unsigned)

    $pem = ($sa.private_key -replace '-----[^-]+-----', '') -replace '\s', ''
    $der = [Convert]::FromBase64String($pem)

    $signature = $null
    $rsa = [System.Security.Cryptography.RSA]::Create()
    if ($rsa.PSObject.Methods.Name -contains 'ImportPkcs8PrivateKey') {
        # PowerShell 7 / .NET Core 3+
        $read = 0
        $rsa.ImportPkcs8PrivateKey($der, [ref]$read)
        $signature = $rsa.SignData($dataBytes, [Security.Cryptography.HashAlgorithmName]::SHA256, [Security.Cryptography.RSASignaturePadding]::Pkcs1)
    } else {
        # Windows PowerShell 5.1
        $cngKey = [Security.Cryptography.CngKey]::Import($der, [Security.Cryptography.CngKeyBlobFormat]::Pkcs8PrivateBlob)
        $cng = New-Object Security.Cryptography.RSACng($cngKey)
        $signature = $cng.SignData($dataBytes, [Security.Cryptography.HashAlgorithmName]::SHA256, [Security.Cryptography.RSASignaturePadding]::Pkcs1)
    }

    $jwt = "$unsigned." + (ConvertTo-Base64Url $signature)
    $response = Invoke-RestMethod -Method Post -Uri 'https://oauth2.googleapis.com/token' `
        -ContentType 'application/x-www-form-urlencoded' `
        -Body @{ grant_type = 'urn:ietf:params:oauth:grant-type:jwt-bearer'; assertion = $jwt }
    return $response.access_token
}

# ---------------------------------------------------------------------------
# 4. FIRESTORE REST CALLS
# ---------------------------------------------------------------------------
function Invoke-Firestore([string]$method, [string]$path, $body = $null, [switch]$AllowNotFound) {
    $uri = "https://firestore.googleapis.com/v1/projects/$script:ProjectId/databases/(default)/documents$path"
    $headers = @{ Authorization = "Bearer $script:Token" }
    try {
        if ($null -ne $body) {
            $json = ConvertTo-Json -InputObject $body -Depth 30 -Compress
            return Invoke-RestMethod -Method $method -Uri $uri -Headers $headers -ContentType 'application/json; charset=utf-8' -Body ([Text.Encoding]::UTF8.GetBytes($json))
        }
        return Invoke-RestMethod -Method $method -Uri $uri -Headers $headers
    } catch {
        $status = $null
        if ($_.Exception.Response) { $status = [int]$_.Exception.Response.StatusCode }
        if ($AllowNotFound -and $status -eq 404) { return $null }
        $detail = if ($_.ErrorDetails -and $_.ErrorDetails.Message) { $_.ErrorDetails.Message } else { $_.Exception.Message }
        throw "Firestore $method $path failed (HTTP $status): $detail"
    }
}

function Find-Customer([string]$emailToFind) {
    foreach ($candidate in @($emailToFind, $emailToFind.ToLower()) | Select-Object -Unique) {
        $query = @{ structuredQuery = @{
            from  = @(@{ collectionId = 'customers' })
            where = @{ fieldFilter = @{ field = @{ fieldPath = 'email' }; op = 'EQUAL'; value = (Fs-String $candidate) } }
            limit = 5
        } }
        $rows = Invoke-Firestore 'POST' ':runQuery' $query
        $docs = @($rows | Where-Object { $_.document })
        if ($docs.Count -gt 1) { throw "More than one customer has the email '$candidate'. Fix the duplicate first." }
        if ($docs.Count -eq 1) { return $docs[0].document }
    }
    return $null
}

# Updates ONLY the named fields. A name in $fieldNames with no value in
# $fields is DELETED from the document (that is how -Undo removes a field
# that did not exist before).
function Update-CustomerFields([string]$uid, [hashtable]$fields, [string[]]$fieldNames) {
    $mask = ($fieldNames | ForEach-Object { 'updateMask.fieldPaths=' + [Uri]::EscapeDataString($_) }) -join '&'
    [void](Invoke-Firestore 'PATCH' "/customers/${uid}?$mask" @{ fields = $fields })
}

# ---------------------------------------------------------------------------
# 5. MAIN
# ---------------------------------------------------------------------------
Write-Host ''
Write-Host 'BLOOM appeal test tool' -ForegroundColor Magenta
Write-Host 'Forces ONE customer account into a restricted state so the appeal flow can be tested.' -ForegroundColor DarkGray
Write-Host ''

if (-not $ServiceAccountPath) {
    $ServiceAccountPath = if ($env:GOOGLE_APPLICATION_CREDENTIALS) { $env:GOOGLE_APPLICATION_CREDENTIALS } else { Join-Path $ScriptDir 'serviceAccountKey.json' }
}
if (-not (Test-Path -LiteralPath $ServiceAccountPath)) {
    throw "Service account key not found: $ServiceAccountPath`nDownload it from Firebase Console > Project settings > Service accounts > Generate new private key, then pass -ServiceAccountPath."
}

$sa = Get-Content -LiteralPath $ServiceAccountPath -Raw | ConvertFrom-Json
if (-not $sa.project_id -or -not $sa.private_key -or -not $sa.client_email) { throw 'That file is not a service account key (project_id / private_key / client_email missing).' }
$script:ProjectId = $sa.project_id

Write-Step "Project: $($script:ProjectId)"
Write-Step 'Signing in to Google...'
$script:Token = Get-AccessToken $sa

Write-Step "Looking for the customer with email $Email ..."
$doc = Find-Customer $Email
if (-not $doc) { throw "No customer found with the email '$Email'. Check the spelling, and that this is a CUSTOMER account." }

$uid = ($doc.name -split '/')[-1]
$fields = $doc.fields
$customerName = if ($fields.name.stringValue) { $fields.name.stringValue } elseif ($fields.username.stringValue) { $fields.username.stringValue } else { '(no name)' }
Write-Step "Found: $customerName   uid: $uid"

# ---- safety checks ----
if ($fields.status.stringValue -eq 'blocked') { throw 'This account is blacklisted (blocked). Refusing to touch it.' }
$userDoc = Invoke-Firestore 'GET' "/users/$uid" -AllowNotFound
if ($userDoc -and $userDoc.fields.role.stringValue -in @('admin', 'super-admin', 'staff', 'employee', 'delivery')) {
    throw "This uid belongs to a team account (role: $($userDoc.fields.role.stringValue)). Refusing to touch it."
}

$backupPath = Join-Path $ScriptDir "restrict-backup-$uid.json"
$logPath = Join-Path $ScriptDir 'restrict-test-log.csv'
$nowUtc = [datetime]::UtcNow

# ---------------------------------------------------------------------------
# UNDO
# ---------------------------------------------------------------------------
if ($Undo) {
    if (-not (Test-Path -LiteralPath $backupPath)) { throw "No backup found for this account ($backupPath). Nothing to undo." }
    $backup = Get-Content -LiteralPath $backupPath -Raw | ConvertFrom-Json

    $restore = @{}
    foreach ($name in $TouchedFields) {
        $saved = $backup.fields.$name
        if ($null -ne $saved) { $restore[$name] = $saved }
    }

    Write-Host ''
    Write-Host "UNDO for $customerName ($uid): puts back the values saved on $($backup.savedAt)." -ForegroundColor Yellow
    if ($DryRun) { Write-Host 'Dry run: nothing was changed.' -ForegroundColor Cyan; return }
    if (-not $Force) {
        $answer = Read-Host 'Type yes to continue'
        if ($answer -ne 'yes') { Write-Host 'Cancelled. Nothing was changed.'; return }
    }

    Update-CustomerFields $uid $restore $TouchedFields
    if (-not $KeepAppeal) { [void](Invoke-Firestore 'DELETE' "/fraud_appeals/$uid" -AllowNotFound) }

    [void](Invoke-Firestore 'POST' '/admin_actions' @{ fields = @{
        actorUid    = (Fs-String 'test-script')
        actorEmail  = (Fs-String ([string]$sa.client_email))
        actorRole   = (Fs-String 'test_script')
        action      = (Fs-String 'test_undo_restriction')
        targetUid   = (Fs-String $uid)
        targetEmail = (Fs-String $Email)
        details     = (Fs-String 'Test restriction undone: account restored to its previous values.')
        reason      = (Fs-String 'Appeal flow test finished.')
        source      = (Fs-String 'Test-Restrict.ps1')
        timestamp   = (Fs-Time $nowUtc)
    } })

    Add-Content -LiteralPath $logPath -Value ('"{0}","undo","{1}","{2}","{3}"' -f $nowUtc.ToString('o'), $Email, $uid, $customerName)
    Remove-Item -LiteralPath $backupPath -Force
    Write-Host ''
    Write-Host "Done. $customerName was restored to how it was before the test." -ForegroundColor Green
    return
}

# ---------------------------------------------------------------------------
# FORCE THE RESTRICTION
# ---------------------------------------------------------------------------
# Merge with what the account already has, exactly like the server does.
$existingFlags = Read-StringList $fields.fraudFlags
$existingCategories = Read-StringList $fields.fraudCategories
$existingCodes = Read-StringList $fields.fraudCodes

$newReasons = @($Activities | ForEach-Object { $_.reason }) + @($TestFlag)
$mergedFlags = Merge-Unique $existingFlags $newReasons
$mergedCategories = Merge-Unique $existingCategories @($Activities | ForEach-Object { $_.category })
$mergedCodes = Merge-Unique $existingCodes @($Activities | ForEach-Object { $_.code })

$score = Get-RiskScore $mergedCodes
$level = Get-RiskLevel $mergedCategories $mergedCodes
$restrictedUntil = $nowUtc.AddDays($RestrictionDays)

$newFields = @{
    isRestricted        = (Fs-Bool $true)
    restrictedUntil     = (Fs-Time $restrictedUntil)
    riskScore           = (Fs-Int $score)
    riskLevel           = (Fs-String $level)
    fraudCodes          = (Fs-List $mergedCodes)
    fraudCategories     = (Fs-List $mergedCategories)
    fraudFlags          = (Fs-List $mergedFlags)
    lastFraudActivityAt = (Fs-Time $nowUtc)
}

Write-Host ''
Write-Host "About to change: $customerName  ($Email)" -ForegroundColor Yellow
Write-Host "  uid:               $uid"
Write-Host "  risk score:        $score / $ScoreCap   (Cash on Delivery turns off at 50)"
Write-Host "  risk level:        $level"
Write-Host "  restricted until:  $($restrictedUntil.ToLocalTime().ToString('yyyy-MM-dd HH:mm'))  (all payment methods off)"
Write-Host "  activity added:    $(@($Activities | ForEach-Object { $_.code }) -join ', ')"
Write-Host "  old appeal:        $(if ($KeepAppeal) { 'kept' } else { 'will be deleted so you can send a fresh one' })"
Write-Host ''

if ($score -lt 50) { Write-Warning "The score is only $score. Something is off with the code table; stopping."; return }

if ($DryRun) { Write-Host 'Dry run: nothing was changed.' -ForegroundColor Cyan; return }

if (-not $Force) {
    $answer = Read-Host 'This is a TEST change to a real account. Type yes to continue'
    if ($answer -ne 'yes') { Write-Host 'Cancelled. Nothing was changed.'; return }
}

# Save the previous values BEFORE changing anything (used by -Undo). If a
# backup from an earlier run exists, keep it: it holds the ORIGINAL state.
if (-not (Test-Path -LiteralPath $backupPath)) {
    $saved = @{}
    foreach ($name in $TouchedFields) { if ($null -ne $fields.$name) { $saved[$name] = $fields.$name } }
    $backupObject = @{ uid = $uid; email = $Email; savedAt = $nowUtc.ToString('o'); fields = $saved }
    ConvertTo-Json -InputObject $backupObject -Depth 30 | Set-Content -LiteralPath $backupPath -Encoding UTF8
    Write-Step "Saved the previous values to $backupPath"
} else {
    Write-Step 'An earlier backup already exists, keeping it (it holds the original values).'
}

Update-CustomerFields $uid $newFields $TouchedFields
Write-Step 'Account updated: restricted, score raised.'

if (-not $KeepAppeal) {
    [void](Invoke-Firestore 'DELETE' "/fraud_appeals/$uid" -AllowNotFound)
    Write-Step 'Old appeal (if any) removed.'
}

# ---- record it ----
[void](Invoke-Firestore 'POST' '/admin_actions' @{ fields = @{
    actorUid    = (Fs-String 'test-script')
    actorEmail  = (Fs-String ([string]$sa.client_email))
    actorRole   = (Fs-String 'test_script')
    action      = (Fs-String 'test_force_restriction')
    targetUid   = (Fs-String $uid)
    targetEmail = (Fs-String $Email)
    details     = (Fs-String "TEST: restriction forced. Score $score, level $level, restricted $RestrictionDays days. Codes added: $(@($Activities | ForEach-Object { $_.code }) -join ', ').")
    reason      = (Fs-String 'Forced for appeal flow testing (Test-Restrict.ps1).')
    source      = (Fs-String 'Test-Restrict.ps1')
    timestamp   = (Fs-Time $nowUtc)
} })
Write-Step 'Recorded in Firestore: admin_actions (action: test_force_restriction).'

if (-not (Test-Path -LiteralPath $logPath)) { Set-Content -LiteralPath $logPath -Value '"time","action","email","uid","name"' -Encoding UTF8 }
Add-Content -LiteralPath $logPath -Value ('"{0}","force_restrict","{1}","{2}","{3}"' -f $nowUtc.ToString('o'), $Email, $uid, $customerName)
Write-Step "Recorded locally: $logPath"

Write-Host ''
Write-Host "Done. $customerName is now restricted with a score of $score." -ForegroundColor Green
Write-Host ''
Write-Host 'To test the appeal:' -ForegroundColor Magenta
Write-Host '  1. Log in as this customer on the website (or app) and go to checkout.'
Write-Host '  2. You should see "Account restricted" with the "Appeal this restriction" link above the message.'
Write-Host '  3. Send an appeal, then open Fraud Analytics as an admin and review it.'
Write-Host "  4. When finished, put the account back:  .\Test-Restrict.ps1 -Email $Email -Undo"
Write-Host ''