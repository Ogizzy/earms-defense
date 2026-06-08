<?php
// pages/api_docs.php — API Reference
require_once __DIR__ . '/../config/db.php';
$apiBase = BASE_URL . '/api/index.php';

$groups = [
  'Scheduling' => [
    ['POST','/defenses/schedule','Schedule a defense for a completed project (runs conflict detection).'],
    ['GET','/defenses','List/search defenses. Filters: projectId, status, date, participant.'],
    ['GET','/defenses/{id}','Full defense detail incl. participants, materials, recordings, scores.'],
    ['PUT','/defenses/{id}/reschedule','Reschedule date/venue (re-checks conflicts).'],
    ['DELETE','/defenses/{id}','Soft-cancel a defense.'],
  ],
  'Participants' => [
    ['POST','/defenses/{id}/participants','Add a participant (one external examiner max).'],
    ['GET','/defenses/{id}/participants','List participants.'],
    ['DELETE','/defenses/{id}/participants/{userId}','Remove participant (blocked after scoring).'],
    ['PUT','/defenses/{id}/attendance','Record attendance (present/absent/pending).'],
  ],
  'Materials' => [
    ['POST','/defenses/{id}/materials/upload','Upload presentation material (versioned).'],
    ['GET','/defenses/{id}/materials','List materials.'],
    ['DELETE','/materials/{id}','Delete material (only before session).'],
  ],
  'Session & Recordings' => [
    ['POST','/defenses/{id}/start-session','Begin the session (status → ongoing).'],
    ['POST','/defenses/{id}/end-session','End session (stops recording, status → completed).'],
    ['POST','/defenses/{id}/recordings/start','Start a recording.'],
    ['POST','/defenses/{id}/recordings/stop','Stop the active recording.'],
    ['POST','/defenses/{id}/recordings/save','Persist recording to storage.'],
    ['GET','/defenses/{id}/recordings','List recordings.'],
    ['DELETE','/defenses/{id}/recordings/{recordingId}','Delete a recording.'],
  ],
  'Scoring' => [
    ['POST','/defenses/{id}/score','Submit a rubric score (locks on submit).'],
    ['GET','/defenses/{id}/scores','List submitted scores.'],
    ['PUT','/defenses/{id}/score/{scoreId}','Amend a score (blocked after finalization).'],
  ],
  'Aggregation & Results' => [
    ['POST','/defenses/{id}/aggregate','Compute weighted aggregate (Sup 30 / Int 40 / Ext 30).'],
    ['POST','/defenses/{id}/finalize','Lock scores and assign final grade.'],
    ['GET','/defenses/{id}/result','Get finalized result with comments.'],
    ['POST','/defenses/{id}/publish','Publish result to stakeholders.'],
  ],
  'Integration & Audit' => [
    ['POST','/defenses/{id}/send-to-exam-officer','Forward final grade to the examination office.'],
    ['GET','/defenses/{id}/audit-log','Per-defense audit trail.'],
  ],
  'Storage Microservice' => [
    ['POST','/files/upload','Upload a file with an access level.'],
    ['GET','/files/{id}','Get file metadata + download URL.'],
    ['DELETE','/files/{id}','Soft-delete a file.'],
    ['GET','/projects/{id}/files','List all files for a project.'],
  ],
];

$pageTitle = 'API Reference'; $activeNav = 'api';
require_once __DIR__ . '/../includes/layout.php';

$mc = ['GET'=>'badge-active','POST'=>'badge-brand','PUT'=>'badge-pending','DELETE'=>'badge-failed'];
?>
<div class="welcome-bar">
  <div><h1>API Reference</h1><p>RESTful JSON API · Defense &amp; Evaluation + Storage microservice</p></div>
  <a href="<?= e($apiBase) ?>/" target="_blank" class="btn btn-outline"><span class="material-symbols-outlined">open_in_new</span>Live index</a>
</div>

<div class="info-ribbon brand" style="margin-bottom:16px;">
  <div class="ir-icon"><span class="material-symbols-outlined">api</span></div>
  <div class="ir-text">
    <div class="ir-title">Base URL</div>
    <div class="ir-sub" style="font-family:monospace;"><?= e($apiBase) ?></div>
  </div>
  <div class="ir-actions"><button class="btn btn-white" onclick="copyText('<?= e($apiBase) ?>')">Copy</button></div>
</div>

<div class="alert alert-info"><span class="material-symbols-outlined">info</span>No authentication is required on this service — identity &amp; access are enforced upstream by the IAM Service and API Gateway. Requests and responses are JSON; <code>PUT</code>/<code>DELETE</code> may be tunnelled via <code>_method</code>.</div>

<?php foreach ($groups as $name => $eps): ?>
<div class="card" style="margin-bottom:14px;">
  <div class="card-head"><div class="card-title"><?= e($name) ?></div></div>
  <div class="table-wrap">
    <table>
      <thead><tr><th style="width:90px;">Method</th><th>Endpoint</th><th>Description</th></tr></thead>
      <tbody>
      <?php foreach ($eps as $ep): ?>
        <tr>
          <td><span class="badge <?= $mc[$ep[0]] ?>"><?= $ep[0] ?></span></td>
          <td style="font-family:monospace;font-size:12px;color:var(--text);"><?= e($ep[1]) ?></td>
          <td style="font-size:12.5px;color:var(--muted);"><?= e($ep[2]) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endforeach; ?>

<div class="card">
  <div class="card-head"><div class="card-title">Example — schedule a defense</div></div>
  <div class="card-body">
    <pre style="background:var(--surface2);border:1.5px solid var(--border);border-radius:10px;padding:14px;font-size:12px;overflow-x:auto;margin:0;">curl -X POST <?= e($apiBase) ?>/defenses/schedule \
  -H "Content-Type: application/json" \
  -d '{
    "project_id": 4,
    "scheduled_at": "2026-09-01 10:00:00",
    "venue": "Senate Hall A",
    "mode": "physical",
    "internal_examiner_ids": [4, 5],
    "external_examiner_id": 6
  }'</pre>
  </div>
</div>
<?php require_once __DIR__ . '/../includes/layout_end.php';
