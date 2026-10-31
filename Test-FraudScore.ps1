<#
.SYNOPSIS
    TEST TOOL for BLOOM: sets ONE customer account to a chosen fraud score so
    you can test how the system reacts at different scores.

.DESCRIPTION
    Pick a ready-made scenario (-Scenario) or your own list of activity codes
    (-Codes). The script REPLACES the account's fraud activity with that set,
    then recomputes the score and level with the SAME points and groups as
    includes/fraud_activity.php, so the dashboard shows what a real case
    would show.

    Scenarios (score / level):
      clean       0   / low       no fraud activity at all
      mild        6   / medium    one weak signal (email risk at signup)
      watch       20  / high      three weak signals in two categories
      almost      49  / high      ONE point below the Cash on Delivery limit
      codoff      50  / high      exactly at the limit: Cash on Delivery turns off
      restricted  53  / critical  banned device included (hard evidence)
      everything  94  / critical  every signal at once

    Add -Restrict to also restrict the account for 30 days (all payment
    methods off, "Appeal this restriction" link shown). Without -Restrict the
    account is NOT restricted and any existing restriction is left alone.

    Every change is recorded in Firestore (admin_actions) and in
    fraudscore-test-log.csv next to this script. The account's previous values
    are saved in fraudscore-backup-<uid>.json so -Undo restores them exactly.

    It refuses to touch a blacklisted account and any admin / staff account.

    It needs a Firebase SERVICE ACCOUNT key. That key can change your whole
    database: keep it private, never commit it, delete it after testing.

.PARAMETER Email
    The customer's email address.

.PARAMETER Scenario
    clean | mild | watch | almost | codoff | restricted | everything

.PARAMETER Codes
    Your own list of activity codes instead of a scenario, for example:
    -Codes velocity_rapid,ip_vpn_proxy   (see the code list in the script)

.PARAMETER Restrict
    Also restrict the account for 30 days.

.PARAMETER ServiceAccountPath
    Path to the service account .json key. Defaults to the
    GOOGLE_APPLICATION_CREDENTIALS environment variable, then to
    .\serviceAccountKey.json

.PARAMETER Undo
    Restores the account to how it was before this script touched it.

.PARAMETER Force
    Skip the "type yes to continue" question.

.PARAMETER DryRun
    Show what WOULD be written, change nothing.

.EXAMPLE
    .\Test-FraudScore.ps1 -Email customer@example.com -Scenario almost -DryRun
    .\Test-FraudScore.ps1 -Email customer@example.com -Scenario codoff
    .\Test-FraudScore.ps1 -Email customer@example.com -Scenario restricted -Restrict
    .\Test-FraudScore.ps1 -Email customer@example.com -Codes velocity_rapid,ip_vpn_proxy
    .\Test-FraudScore.ps1 -Email customer@example.com -Undo
#>
[CmdletBinding()]
param(
    [Parameter(Mandatory = $true)][string]$Email,
    [ValidateSet('clean', 'mild', 'watch', 'almost', 'codoff', 'restricted', 'everything')][string]$Scenario,
    [string[]]$Codes,
    [switch]$Restrict,
    [string]$ServiceAccountPath,
    [switch]$Undo,
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

# code -> tier + group + category + the reason text the system writes itself
# (the dashboard finds some signs by those words, so keep them the same).
$CodeRules = @{
    banned_device            = @{ tier = 'very_strong';     group = 'device';        category = 'fake_transaction'; reason = 'Order placed from a previously banned device' }
    price_mismatch           = @{ tier = 'very_strong';     group = 'price';         category = 'payment_fraud';    reason = 'Payment amount mismatch: cart prices sent were lower than current product prices' }
    phone_disposable_voip    = @{ tier = 'strong';          group = 'phone_quality'; category = 'fake_transaction'; reason = 'Disposable/VOIP phone number used at checkout' }
    velocity_rapid           = @{ tier = 'strong';          group = 'velocity';      category = 'fake_transaction'; reason = 'Multiple rapid checkouts flagged (2 prior orders in under 5 minutes)' }
    history_amount_deviation = @{ tier = 'strong';          group = 'spending';      category = 'payment_fraud';    reason = "Order total P5,000.00 is above this customer's usual spending range (average P800.00 + 2 standard deviations = P2,000.00, from 4 orders in the last 60 days)" }
    new_account_high_value   = @{ tier = 'strong';          group = 'spending';      category = 'payment_fraud';    reason = "New account (5 days old) placed a P5,000.00 order, above the shop's top 5% of recent orders (P3,000.00)" }
    ip_tor_abuse             = @{ tier = 'moderate_strong'; group = 'network';       category = 'account_takeover'; reason = 'High-risk IP reputation (Tor/abuse flagged)' }
    ip_vpn_proxy             = @{ tier = 'moderate_strong'; group = 'network';       category = 'account_takeover'; reason = 'VPN/Proxy detected' }
    geo_mismatch             = @{ tier = 'moderate_strong'; group = 'location';      category = 'account_takeover'; reason = 'Severe Device-to-Destination Mismatch' }
    velocity_repeat          = @{ tier = 'supporting';      group = 'velocity';      category = 'fake_transaction'; reason = 'Repeat checkout within a 5-minute window' }
    phone_reuse              = @{ tier = 'supporting';      group = 'phone_link';    category = 'fake_transaction'; reason = 'Phone number already associated with a different account' }
    address_reuse            = @{ tier = 'supporting';      group = 'address_link';  category = 'fake_transaction'; reason = 'Delivery address already associated with a different account (4 accounts share it, limit 3, no successful delivery there yet)' }
    email_risk_signup        = @{ tier = 'supporting';      group = 'email';         category = 'fake_transaction'; reason = 'Elevated email risk at signup' }
}
$ScoreCap = 100
$HardEvidenceCodes = @('banned_device')
$StudyCategories = @('account_takeover', 'payment_fraud', 'fake_transaction', 'malicious_return')
$RestrictionDays = 30
$CodOffScore = 50

# Ready-made scenarios (each one adds up to the score shown in its name/comment).
$Scenarios = @{
    clean      = @()                                                                                                      # 0
    mild       = @('email_risk_signup')                                                                                   # 6
    watch      = @('ip_vpn_proxy', 'email_risk_signup', 'phone_reuse')                                                    # 8+6+6 = 20
    almost     = @('price_mismatch', 'phone_disposable_voip', 'velocity_rapid', 'ip_vpn_proxy', 'email_risk_signup')      # 15+10+10+8+6 = 49
    codoff     = @('phone_disposable_voip', 'velocity_rapid', 'history_amount_deviation', 'ip_vpn_proxy', 'phone_reuse', 'email_risk_signup')  # 10+10+10+8+6+6 = 50
    restricted = @('banned_device', 'phone_disposable_voip', 'velocity_rapid', 'history_amount_deviation', 'ip_vpn_proxy') # 15+10+10+10+8 = 53
    everything = @('banned_device', 'price_mismatch', 'phone_disposable_voip', 'velocity_rapid', 'history_amount_deviation', 'ip_vpn_proxy', 'geo_mismatch', 'phone_reuse', 'address_reuse', 'email_risk_signup')  # 94
}
$TestFlag = 'TEST: fraud score set by Test-FraudScore.ps1 (not a real fraud case)'

# Fields this script may change (and -Undo restores). The two restriction
# fields are only touched when -Restrict is used.
$BaseFields = @('riskScore', 'riskLevel', 'fraudCodes', 'fraudCategories', 'fraudFlags', 'lastFraudActivityAt')
$RestrictFields = @('isRestricted', 'restrictedUntil')

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
Write-Host 'BLOOM fraud score test tool' -ForegroundColor Magenta
Write-Host 'Sets ONE customer account to a chosen fraud score so you can test how the system reacts.' -ForegroundColor DarkGray
Write-Host ''

if (-not $Undo) {
    if ($Scenario -and $Codes) { throw 'Use either -Scenario or -Codes, not both.' }
    if (-not $Scenario -and -not $Codes) { throw 'Choose a score: add -Scenario (clean, mild, watch, almost, codoff, restricted, everything) or -Codes code1,code2. Add -Undo to restore an account.' }
    if ($Codes) {
        $Codes = @($Codes | ForEach-Object { $_ -split ',' } | ForEach-Object { $_.Trim() } | Where-Object { $_ })
        foreach ($c in $Codes) {
            if (-not $CodeRules.ContainsKey($c)) { throw "Unknown code '$c'. Valid codes: $(($CodeRules.Keys | Sort-Object) -join ', ')" }
        }
    }
}

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

$backupPath = Join-Path $ScriptDir "fraudscore-backup-$uid.json"
$logPath = Join-Path $ScriptDir 'fraudscore-test-log.csv'
$nowUtc = [datetime]::UtcNow

# ---------------------------------------------------------------------------
# UNDO
# ---------------------------------------------------------------------------
if ($Undo) {
    if (-not (Test-Path -LiteralPath $backupPath)) { throw "No backup found for this account ($backupPath). Nothing to undo." }
    $backup = Get-Content -LiteralPath $backupPath -Raw | ConvertFrom-Json

    # Restore every field the first run touched; a field that did not exist
    # before is deleted again (it is in the mask but has no saved value).
    $touchedNames = @($backup.touchedFields)
    $restore = @{}
    foreach ($name in $touchedNames) {
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

    Update-CustomerFields $uid $restore $touchedNames

    [void](Invoke-Firestore 'POST' '/admin_actions' @{ fields = @{
        actorUid    = (Fs-String 'test-script')
        actorEmail  = (Fs-String ([string]$sa.client_email))
        actorRole   = (Fs-String 'test_script')
        action      = (Fs-String 'test_undo_fraud_score')
        targetUid   = (Fs-String $uid)
        targetEmail = (Fs-String $Email)
        details     = (Fs-String 'Test fraud score undone: account restored to its previous values.')
        reason      = (Fs-String 'Fraud score test finished.')
        source      = (Fs-String 'Test-FraudScore.ps1')
        timestamp   = (Fs-Time $nowUtc)
    } })

    if (-not (Test-Path -LiteralPath $logPath)) { Set-Content -LiteralPath $logPath -Value '"time","action","email","uid","name","detail"' -Encoding UTF8 }
    Add-Content -LiteralPath $logPath -Value ('"{0}","undo","{1}","{2}","{3}",""' -f $nowUtc.ToString('o'), $Email, $uid, $customerName)
    Remove-Item -LiteralPath $backupPath -Force
    Write-Host ''
    Write-Host "Done. $customerName was restored to how it was before the test." -ForegroundColor Green
    return
}

# ---------------------------------------------------------------------------
# SET THE SCORE
# ---------------------------------------------------------------------------
$chosenCodes = if ($Scenario) { @($Scenarios[$Scenario]) } else { @($Codes) }
$label = if ($Scenario) { "scenario '$Scenario'" } else { 'custom codes' }

# REPLACE (not merge) so the score is exactly what the chosen codes add up to.
$newCodes = @($chosenCodes | Select-Object -Unique)
$newCategories = @($newCodes | ForEach-Object { $CodeRules[$_].category } | Select-Object -Unique)
$newFlags = @($newCodes | ForEach-Object { $CodeRules[$_].reason })
if ($newCodes.Count -gt 0) { $newFlags += $TestFlag }

$score = Get-RiskScore $newCodes
$level = Get-RiskLevel $newCategories $newCodes

$touchedNames = @($BaseFields)
$newFields = @{
    riskScore           = (Fs-Int $score)
    riskLevel           = (Fs-String $level)
    fraudCodes          = (Fs-List $newCodes)
    fraudCategories     = (Fs-List $newCategories)
    fraudFlags          = (Fs-List $newFlags)
    lastFraudActivityAt = (Fs-Time $nowUtc)
}

$restrictedUntil = $nowUtc.AddDays($RestrictionDays)
if ($Restrict) {
    $touchedNames += $RestrictFields
    $newFields['isRestricted'] = (Fs-Bool $true)
    $newFields['restrictedUntil'] = (Fs-Time $restrictedUntil)
}

Write-Host ''
Write-Host "About to change: $customerName  ($Email)" -ForegroundColor Yellow
Write-Host "  uid:               $uid"
Write-Host "  using:             $label"
Write-Host "  risk score:        $score / $ScoreCap   (Cash on Delivery turns off at $CodOffScore)"
Write-Host "  risk level:        $level"
Write-Host "  Cash on Delivery:  $(if ($score -ge $CodOffScore) { 'will be OFF' } else { 'stays available' })"
Write-Host "  restriction:       $(if ($Restrict) { 'YES, until ' + $restrictedUntil.ToLocalTime().ToString('yyyy-MM-dd HH:mm') + ' (all payment methods off)' } else { 'not changed' })"
Write-Host "  activity set to:   $(if ($newCodes.Count -gt 0) { $newCodes -join ', ' } else { '(none - clean)' })"
Write-Host '  note:              the old activity on this account is REPLACED (a backup is kept for -Undo)'
Write-Host ''

if ($DryRun) { Write-Host 'Dry run: nothing was changed.' -ForegroundColor Cyan; return }

if (-not $Force) {
    $answer = Read-Host 'This is a TEST change to a real account. Type yes to continue'
    if ($answer -ne 'yes') { Write-Host 'Cancelled. Nothing was changed.'; return }
}

# Save the previous values BEFORE changing anything (used by -Undo). If a
# backup from an earlier run exists, keep it: it holds the ORIGINAL state.
# A later run that touches MORE fields (adds -Restrict) is handled below.
if (-not (Test-Path -LiteralPath $backupPath)) {
    $saved = @{}
    foreach ($name in ($BaseFields + $RestrictFields)) { if ($null -ne $fields.$name) { $saved[$name] = $fields.$name } }
    $backupObject = @{ uid = $uid; email = $Email; savedAt = $nowUtc.ToString('o'); touchedFields = $touchedNames; fields = $saved }
    ConvertTo-Json -InputObject $backupObject -Depth 30 | Set-Content -LiteralPath $backupPath -Encoding UTF8
    Write-Step "Saved the previous values to $backupPath"
} else {
    Write-Step 'An earlier backup already exists, keeping it (it holds the original values).'
    $existingBackup = Get-Content -LiteralPath $backupPath -Raw | ConvertFrom-Json
    $missing = @($touchedNames | Where-Object { @($existingBackup.touchedFields) -notcontains $_ })
    if ($missing.Count -gt 0) {
        # Restriction fields were not in the first run. Their CURRENT values are
        # still the original ones (the first run never touched them), so add them.
        $savedMap = @{}
        foreach ($p in $existingBackup.fields.PSObject.Properties) { $savedMap[$p.Name] = $p.Value }
        foreach ($name in $missing) { if ($null -ne $fields.$name) { $savedMap[$name] = $fields.$name } }
        $backupObject = @{ uid = $uid; email = $Email; savedAt = $existingBackup.savedAt; touchedFields = @(@($existingBackup.touchedFields) + $missing); fields = $savedMap }
        ConvertTo-Json -InputObject $backupObject -Depth 30 | Set-Content -LiteralPath $backupPath -Encoding UTF8
        Write-Step "Backup extended to cover: $($missing -join ', ')"
    }
}

Update-CustomerFields $uid $newFields $touchedNames
Write-Step "Account updated: score set to $score."

# ---- record it ----
[void](Invoke-Firestore 'POST' '/admin_actions' @{ fields = @{
    actorUid    = (Fs-String 'test-script')
    actorEmail  = (Fs-String ([string]$sa.client_email))
    actorRole   = (Fs-String 'test_script')
    action      = (Fs-String 'test_set_fraud_score')
    targetUid   = (Fs-String $uid)
    targetEmail = (Fs-String $Email)
    details     = (Fs-String "TEST: fraud score set to $score ($level) using $label. Codes: $(if ($newCodes.Count -gt 0) { $newCodes -join ', ' } else { 'none' }). Restricted: $(if ($Restrict) { 'yes' } else { 'no change' }).")
    reason      = (Fs-String 'Fraud score test (Test-FraudScore.ps1).')
    source      = (Fs-String 'Test-FraudScore.ps1')
    timestamp   = (Fs-Time $nowUtc)
} })
Write-Step 'Recorded in Firestore: admin_actions (action: test_set_fraud_score).'

if (-not (Test-Path -LiteralPath $logPath)) { Set-Content -LiteralPath $logPath -Value '"time","action","email","uid","name","detail"' -Encoding UTF8 }
Add-Content -LiteralPath $logPath -Value ('"{0}","set_score","{1}","{2}","{3}","score {4} {5}{6}"' -f $nowUtc.ToString('o'), $Email, $uid, $customerName, $score, $level, $(if ($Restrict) { ' restricted' } else { '' }))
Write-Step "Recorded locally: $logPath"

Write-Host ''
Write-Host "Done. $customerName now has a fraud score of $score ($level)." -ForegroundColor Green
Write-Host ''
Write-Host 'What to check:' -ForegroundColor Magenta
Write-Host "  1. Fraud Analytics (admin): the account shows score $score and level $level."
Write-Host "  2. Checkout as this customer: Cash on Delivery is $(if ($score -ge $CodOffScore) { 'hidden/disabled' } else { 'still available' })."
if ($Restrict) { Write-Host '  3. Checkout also shows "Account restricted" with the appeal link, and no payment method works.' }
Write-Host "  Put the account back when finished:  .\Test-FraudScore.ps1 -Email $Email -Undo"
Write-Host ''