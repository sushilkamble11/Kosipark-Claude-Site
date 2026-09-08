const API = "/api/ops/index.php";
const fileDemo = location.protocol === "file:";
let csrf = "";
let snapshot = null;
let selectedEventId = null;
let toastTimer = null;
let demoLoggedIn = fileDemo && sessionStorage.getItem("kosipark-ops-demo") === "1";

const $ = id => document.getElementById(id);
const escapeHtml = value => String(value ?? "").replace(/[&<>'"]/g, character => ({"&":"&amp;","<":"&lt;",">":"&gt;","'":"&#39;",'"':"&quot;"})[character]);
const makeId = () => globalThis.crypto?.randomUUID?.() || `${Date.now().toString(16)}-${Math.random().toString(16).slice(2)}`;
const nowIso = () => new Date().toISOString();
const timeOnly = value => value ? new Intl.DateTimeFormat("en-AU", {hour:"2-digit", minute:"2-digit", second:"2-digit", hour12:false}).format(new Date(value)) : "—";
const relativeTime = value => {
  if (!value) return "Never checked";
  const seconds = Math.max(0, Math.round((Date.now() - new Date(value).getTime()) / 1000));
  if (seconds < 60) return `Last seen ${seconds}s ago`;
  if (seconds < 3600) return `Last seen ${Math.floor(seconds / 60)}m ago`;
  return `Last seen ${Math.floor(seconds / 3600)}h ago`;
};

const demoState = {
  mappings: [
    {id:"map-cabin-4", unit_id:"a53d22d9-0e96-431b-adb6-4c7de0611ee5", unit_name:"Cabin 4", lock_id:"7120044", lock_name:"Cabin 4 · Front door", gate_access:true, updated_at:new Date(Date.now()-86400000).toISOString()},
    {id:"map-chalet-2", unit_id:"fd5d358d-8041-42dc-8c2d-aaf65a8fb992", unit_name:"Chalet 2", lock_id:"7120042", lock_name:"Chalet 2 · Entry", gate_access:true, updated_at:new Date(Date.now()-172800000).toISOString()},
  ],
  inventory: [
    {lock_id:"7120041", name:"Cabin 1 · Front door", battery:87, has_gateway:true},
    {lock_id:"7120042", name:"Chalet 2 · Entry", battery:64, has_gateway:true},
    {lock_id:"7120044", name:"Cabin 4 · Front door", battery:41, has_gateway:true},
  ],
  sync: {ttlock_at:new Date(Date.now()-8000).toISOString(), guestpoint_at:new Date(Date.now()-10000).toISOString()},
  issues: [
    {id:"issue-passcode-4", category:"Provider error", title:"Cabin 4 passcode could not be updated", booking:"KTP-52440", lock_id:"7120044", retries:3, last_attempt:new Date(Date.now()-46000).toISOString(), replayable:true, event_id:"evt-failed-4"},
  ],
  events: [
    event("evt-updated-1", -9000, "GuestPoint", "reservation.updated", "TTLock", "Delivered", [step("Event received","Source: GuestPoint"),step("Mapping resolved","Booking KTP-51907 → Lock 7120042"),step("Passcode updated","Provider confirmed operation")]),
    event("evt-processing-1", -22000, "GuestPoint", "passcode.created", "TTLock", "Processing", [step("Event received","Booking KTP-53114"),step("Mapping resolved","Lock 7120041"),step("Provider request","Awaiting TTLock response","processing")]),
    event("evt-gate-1", -35000, "TTLock", "visitor.synced", "GuestPoint", "Delivered", [step("Inventory update received","Lock 7120041"),step("Local state reconciled","No differences found")]),
    event("evt-failed-4", -46000, "GuestPoint", "passcode.updated", "TTLock", "Failed", [step("Event received","Booking KTP-52440"),step("Mapping resolved","Cabin 4 → Lock 7120044"),step("Provider request","POST /v3/lock/passcode/update"),step("Provider error","400 Bad Request — invalid passcode format","failed"),step("Retry paused","Attempt 3 of 5","processing")]),
    event("evt-cancel-1", -61000, "GuestPoint", "reservation.cancelled", "TTLock", "Delivered", [step("Cancellation received","Booking KTP-51182"),step("Passcode deleted","Lock 7120041")]),
    event("evt-boom-1", -79000, "GuestPoint", "visitor.queued", "Boom gate", "Processing", [step("Booking mapped","Vehicle access requested"),step("Operation held","Boom-gate write disabled","processing")]),
  ],
  audit: [],
};

function event(id, offset, source, name, target, status, trace) {
  return {id, at:new Date(Date.now()+offset).toISOString(), source, event:name, target, status, correlation_id:makeId(), trace};
}

function step(label, detail, status="complete") { return {label, detail, status}; }

function demoProviders() {
  return {
    guestpoint:{configured:true, key:"••••9K2A", property:"••••31F8", scope:"Bookings, guests, stays", version:"v12", rotation_due:"22 Sep 2026"},
    ttlock:{configured:true, client_id:"••••82DC", token:"••••07AB", scope:"Locks, passcodes, gateways", version:"v9", rotation_due:"18 Sep 2026"},
    boomgate:{configured:false, park_id:null, scope:"Entry, exit, visitors", version:"—", rotation_due:"Awaiting setup", note:"Waiting for the vendor to confirm control direction and production authentication."},
  };
}

async function request(action, options = {}) {
  if (fileDemo) return demoRequest(action, options);
  const response = await fetch(`${API}?action=${encodeURIComponent(action)}${options.query || ""}`, {
    method: options.method || "GET",
    credentials: "same-origin",
    headers: {"Content-Type":"application/json", ...(csrf ? {"X-Ops-CSRF":csrf} : {})},
    body: options.body ? JSON.stringify(options.body) : undefined,
  });
  const data = await response.json().catch(() => ({message:"The middleware returned an unreadable response."}));
  if (!response.ok) throw Object.assign(new Error(data.message || "Request failed."), {status:response.status});
  if (data.csrf) csrf = data.csrf;
  return data;
}

function demoRequest(action, options = {}) {
  if (action === "login") {
    if (options.body?.username !== "admin" || options.body?.password !== "demo") throw Object.assign(new Error("For this local preview use admin / demo."), {status:401});
    demoLoggedIn = true;
    sessionStorage.setItem("kosipark-ops-demo", "1");
    return Promise.resolve({ok:true, csrf:"file-preview", user:{username:"admin", role:"administrator"}});
  }
  if (action === "forgot-password") return Promise.resolve({ok:true, message:"Local preview: use admin / demo. Email delivery runs only on the hosted backend."});
  if (action === "reset-password") return Promise.resolve({ok:true, message:"Local preview password reset simulated. Use admin / demo to sign in."});
  if (!demoLoggedIn) throw Object.assign(new Error("Please sign in."), {status:401});
  if (action === "overview") return Promise.resolve({ok:true, csrf:"file-preview", user:{username:"admin", role:"administrator"}, providers:demoProviders(), ...demoState});
  if (action === "logout") { demoLoggedIn=false; sessionStorage.removeItem("kosipark-ops-demo"); return Promise.resolve({ok:true}); }
  if (action === "mapping" && options.method === "POST") {
    const mapping = {...options.body, id:options.body.id || makeId(), updated_at:nowIso()};
    demoState.mappings = demoState.mappings.filter(item => item.id !== mapping.id);
    demoState.mappings.push(mapping);
    demoState.events.unshift(event(makeId(), 0, "Middleware", "mapping.updated", "TTLock", "Delivered", [step("Mapping validated",mapping.unit_name),step("Mapping saved",`Lock ${mapping.lock_id}`)]));
    return Promise.resolve({ok:true, mapping});
  }
  if (action === "mapping" && options.method === "DELETE") {
    const id = new URLSearchParams(options.query || "").get("id");
    demoState.mappings = demoState.mappings.filter(item => item.id !== id);
    demoState.events.unshift(event(makeId(), 0, "Middleware", "mapping.deleted", "TTLock", "Delivered", [step("Mapping removed","No provider data was deleted") ]));
    return Promise.resolve({ok:true});
  }
  if (action === "sync-ttlock") {
    demoState.sync.ttlock_at = nowIso();
    demoState.events.unshift(event(makeId(), 0, "TTLock", "locks.inventory_synced", "Middleware", "Delivered", [step("Provider request","GET /v3/lock/list"),step("Inventory stored","3 locks") ]));
    return Promise.resolve({ok:true, count:3});
  }
  if (action === "check-guestpoint") {
    demoState.sync.guestpoint_at = nowIso();
    demoState.events.unshift(event(makeId(), 0, "GuestPoint", "reservations.changes_checked", "Middleware", "Delivered", [step("Change feed requested","Last 48 hours"),step("Changes received","3 reservations") ]));
    return Promise.resolve({ok:true, changed_reservations:3});
  }
  if (action === "test-connection") return Promise.resolve({ok:true, provider:options.body.provider, latency_ms:Math.floor(120+Math.random()*180)});
  if (action === "retry-issue") {
    const issue = demoState.issues.find(item => item.id === options.body.id);
    if (!issue) throw Object.assign(new Error("Issue not found."), {status:404});
    issue.retries += 1;
    issue.last_attempt = nowIso();
    demoState.events.unshift(event(makeId(), 0, "Middleware", "passcode.retry_scheduled", "TTLock", "Processing", [step("Retry authorised","Idempotency check passed"),step("Provider request scheduled","Attempt queued","processing") ]));
    return Promise.resolve({ok:true});
  }
  if (action === "diagnose") return Promise.resolve({ok:true, provider:"demo", model:"read-only preview", status:"proposal_only", text:"Diagnosis\nThe lock provider rejected the passcode format after the reservation update was mapped successfully. The failure is isolated to the provider request.\n\nProposed remediation\nValidate the generated passcode against the KAS lock policy, then replay the operation once with the same idempotency key. Do not create a second key.\n\nVerification\nConfirm the provider acknowledgement, read back the active key list, and verify the booking-to-lock mapping.\n\nRisk\nA blind retry could create duplicate or conflicting access. Human approval is required before execution."});
  throw Object.assign(new Error("Unknown demo action."), {status:404});
}

function setHealth(elementId, configured, connectedText, waitingText) {
  const element = $(elementId);
  element.className = `health ${configured ? "connected" : "blocked"}`;
  element.innerHTML = `<i></i>${configured ? connectedText : waitingText}`;
}

function render(data) {
  snapshot = data;
  data.events ||= [];
  data.issues ||= [];
  data.mappings ||= [];
  data.inventory ||= [];
  setHealth("gp-health", data.providers.guestpoint.configured, "Connected", "Setup needed");
  setHealth("tt-health", data.providers.ttlock.configured, "Connected", "Setup needed");
  setHealth("bg-health", data.providers.boomgate.configured, "Connected", "Awaiting vendor");
  $("gp-seen").textContent = relativeTime(data.sync.guestpoint_at);
  $("tt-seen").textContent = relativeTime(data.sync.ttlock_at);
  $("issue-count").textContent = data.issues.length;
  $("issue-count-nav").textContent = data.issues.length;
  $("issue-count-nav").hidden = data.issues.length === 0;
  document.querySelector(".operator strong").textContent = data.user?.username || "Administrator";
  renderEvents();
  renderIssues();
  renderConnections();
  renderMappings();
  $("lock-options").innerHTML = data.inventory.map(lock => `<option value="${escapeHtml(lock.lock_id)}">${escapeHtml(lock.name)}</option>`).join("");
  if (!selectedEventId && data.events[0]) selectedEventId = data.events[0].id;
  renderTrace();
}

function providerBadge(name) {
  const label = name === "GuestPoint" ? "GP" : name === "Boom gate" ? "BG" : name === "Middleware" ? "MW" : "TT";
  return `<span class="mini-logo">${label}</span><span>${escapeHtml(name)}</span>`;
}

function filteredEvents() {
  const status = $("status-filter").value.toLowerCase();
  const provider = $("provider-filter").value;
  const query = $("search").value.trim().toLowerCase();
  return snapshot.events.filter(item => {
    const statusMatch = status === "all" || item.status.toLowerCase() === status;
    const providerMatch = provider === "all" || item.source === provider || item.target === provider;
    const haystack = `${item.event} ${item.source} ${item.target} ${item.correlation_id} ${item.trace?.map(step => step.detail).join(" ")}`.toLowerCase();
    return statusMatch && providerMatch && (!query || haystack.includes(query));
  });
}

function renderEvents() {
  const rows = filteredEvents();
  $("event-empty").hidden = rows.length > 0;
  $("event-rows").innerHTML = rows.map(item => `<tr data-event-id="${escapeHtml(item.id)}" data-status="${item.status.toLowerCase()}" class="${item.id === selectedEventId ? "selected" : ""}">
    <td>${escapeHtml(timeOnly(item.at))}</td><td><span class="provider-cell">${providerBadge(item.source)}</span></td><td>${escapeHtml(item.event)}</td><td><span class="provider-cell">${providerBadge(item.target)}</span></td><td><span class="status-label ${item.status.toLowerCase()}"><i></i>${escapeHtml(item.status)}</span></td><td><code class="correlation">${escapeHtml(item.correlation_id)}</code></td>
  </tr>`).join("");
}

function renderIssues() {
  const issues = snapshot.issues;
  if (!issues.length) { $("issues-list").innerHTML = `<div class="issues-empty"><strong>Everything is clear.</strong><br>No integration failures need attention.</div>`; return; }
  const groups = Object.groupBy ? Object.groupBy(issues, item => item.category || "Integration issue") : issues.reduce((all,item) => ((all[item.category || "Integration issue"] ||= []).push(item), all), {});
  $("issues-list").innerHTML = Object.entries(groups).map(([category, items]) => `<section class="issue-group"><div class="issue-group-head"><span>${escapeHtml(category)} <span class="count">${items.length}</span></span><span>⌃</span></div>${items.map(issue => `<article class="issue-card"><div class="issue-title"><span>×</span><strong>${escapeHtml(issue.title)}</strong></div><div class="issue-meta"><span><small>GuestPoint booking</small>${escapeHtml(issue.booking || "—")}</span><span><small>TTLock lock ID</small>${escapeHtml(issue.lock_id || "—")}</span><span><small>Retry count</small>${escapeHtml(issue.retries || 0)}</span><span><small>Last attempt</small>${escapeHtml(timeOnly(issue.last_attempt))}</span></div><div class="issue-actions"><button class="button ghost" data-view-event="${escapeHtml(issue.event_id || "")}">View trace</button><button class="button ghost" data-diagnose-issue="${escapeHtml(issue.id)}">Diagnose</button>${issue.replayable ? `<button class="button primary" data-retry-issue="${escapeHtml(issue.id)}">Retry safely</button>` : ""}</div></article>`).join("")}</section>`).join("");
}

function providerRows() {
  const providers = snapshot.providers;
  return [
    {id:"guestpoint", name:"GuestPoint", secret:providers.guestpoint.key, last:snapshot.sync.guestpoint_at, ...providers.guestpoint},
    {id:"ttlock", name:"TTLock", secret:providers.ttlock.token, last:snapshot.sync.ttlock_at, ...providers.ttlock},
    {id:"boomgate", name:"Boom gate", secret:providers.boomgate.park_id, last:null, ...providers.boomgate},
  ];
}

function renderConnections() {
  $("connection-rows").innerHTML = providerRows().map(provider => `<tr><td><span class="connection-name">${providerBadge(provider.name)}</span></td><td>${escapeHtml(provider.scope || defaultScope(provider.id))}</td><td><span class="status-label ${provider.configured ? "" : "failed"}"><i></i>${provider.configured ? "Connected" : "Setup needed"}</span></td><td>${escapeHtml(provider.last ? relativeTime(provider.last).replace("Last seen ","") : "Never")}</td><td><code class="masked-secret">${escapeHtml(provider.secret || "Not configured")}</code></td><td>${escapeHtml(provider.version || "v1")}</td><td>${escapeHtml(provider.rotation_due || "Not scheduled")}</td><td><button class="test-button" data-test-provider="${provider.id}" ${provider.id === "boomgate" ? "disabled" : ""}>Test connection</button></td></tr>`).join("");
}

function defaultScope(provider) {
  return provider === "guestpoint" ? "Bookings, guests, stays" : provider === "ttlock" ? "Locks, passcodes, gateways" : "Entry, exit, visitors";
}

function renderTrace() {
  const selected = snapshot.events.find(item => item.id === selectedEventId);
  if (!selected) { $("detail-correlation").textContent = "Select an event"; $("trace").innerHTML = `<div class="trace-empty">Choose an event to follow it across providers.</div>`; return; }
  $("detail-correlation").textContent = `Correlation ID: ${selected.correlation_id}`;
  const trace = selected.trace?.length ? selected.trace : [step("Event recorded",`${selected.source} → ${selected.target}`, selected.status.toLowerCase())];
  $("trace").innerHTML = trace.map((item,index) => `<article class="trace-step ${escapeHtml(item.status || "complete")}"><time>${escapeHtml(timeOnly(new Date(new Date(selected.at).getTime()+index*1000)))}</time><strong>${escapeHtml(item.label)}</strong><p>${escapeHtml(item.detail || "")}</p></article>`).join("");
}

function renderMappings() {
  const root = $("mapping-table");
  if (!snapshot.mappings.length) { root.innerHTML = `<div class="mapping-empty"><strong>No access mappings yet</strong><span>Import TTLock locks, then connect each physical room.</span><button class="button primary" data-empty-add>Add first mapping</button></div>`; return; }
  root.innerHTML = `<div class="connection-table-wrap"><table class="mapping-table"><thead><tr><th>GuestPoint unit</th><th>TTLock</th><th>Gate access</th><th>Updated</th><th></th></tr></thead><tbody>${snapshot.mappings.map(mapping => `<tr><td><strong>${escapeHtml(mapping.unit_name)}</strong><small>${escapeHtml(mapping.unit_id)}</small></td><td><strong>${escapeHtml(mapping.lock_name || `Lock ${mapping.lock_id}`)}</strong><small>${escapeHtml(mapping.lock_id)}</small></td><td>${mapping.gate_access ? "Included" : "No"}</td><td>${escapeHtml(relativeTime(mapping.updated_at).replace("Last seen ",""))}</td><td class="row-actions"><button class="row-action" data-edit="${escapeHtml(mapping.id)}">Edit</button><button class="row-action danger" data-delete="${escapeHtml(mapping.id)}">Remove</button></td></tr>`).join("")}</tbody></table></div>`;
}

function showToast(message, isError=false) {
  const toast = $("action-message");
  clearTimeout(toastTimer);
  toast.textContent = message;
  toast.classList.toggle("error", isError);
  toast.hidden = false;
  toastTimer = setTimeout(() => { toast.hidden = true; }, 5200);
}

async function load() {
  try {
    const data = await request("overview");
    $("login-view").hidden = true;
    $("app-view").hidden = false;
    render(data);
  } catch (error) {
    if (error.status === 401) { $("login-view").hidden=false; $("app-view").hidden=true; return; }
    $("login-error").textContent = error.message;
  }
}

function openMapping(mapping={}) {
  $("dialog-title").textContent = mapping.id ? "Edit access mapping" : "Add access mapping";
  $("mapping-id").value = mapping.id || "";
  $("unit-name").value = mapping.unit_name || "";
  $("unit-id").value = mapping.unit_id || "";
  $("lock-id").value = mapping.lock_id || "";
  $("lock-name").value = mapping.lock_name || "";
  $("gate-access").checked = !!mapping.gate_access;
  $("mapping-error").textContent = "";
  $("mapping-dialog").showModal();
}

$("login-form").addEventListener("submit", async eventObject => {
  eventObject.preventDefault();
  $("login-error").textContent = "";
  try { const data = await request("login", {method:"POST", body:{username:$("username").value.trim().toLowerCase(), password:$("password").value}}); csrf=data.csrf; $("password").value=""; await load(); }
  catch (error) { $("login-error").textContent = error.message; }
});

$("event-rows").addEventListener("click", eventObject => {
  const row = eventObject.target.closest("[data-event-id]");
  if (!row) return;
  selectedEventId = row.dataset.eventId;
  renderEvents(); renderTrace();
});

$("issues-list").addEventListener("click", async eventObject => {
  const view = eventObject.target.closest("[data-view-event]");
  const retry = eventObject.target.closest("[data-retry-issue]");
  const diagnose = eventObject.target.closest("[data-diagnose-issue]");
  if (view?.dataset.viewEvent) { selectedEventId=view.dataset.viewEvent; renderEvents(); renderTrace(); $("event-detail").scrollIntoView({behavior:"smooth", block:"center"}); }
  if (retry) {
    retry.disabled=true;
    try { await request("retry-issue", {method:"POST", body:{id:retry.dataset.retryIssue}}); showToast("Retry scheduled after an idempotency check."); await load(); }
    catch (error) { showToast(error.message, true); }
    finally { retry.disabled=false; }
  }
  if (diagnose) {
    const dialog=$("ai-diagnostic");
    const body=$("diagnostic-body");
    dialog.showModal(); body.classList.add("loading"); body.textContent="Reviewing the redacted trace…";
    try {
      const result=await request("diagnose", {method:"POST", body:{id:diagnose.dataset.diagnoseIssue}});
      body.classList.remove("loading"); body.innerHTML="";
      const article=document.createElement("article"); article.className="diagnostic-result";
      const title=document.createElement("h3"); title.textContent="Proposed diagnosis";
      const text=document.createElement("div"); text.textContent=result.text;
      const meta=document.createElement("small"); meta.textContent=`${result.provider} · ${result.model} · proposal only`;
      article.append(title,text,meta); body.append(article);
    } catch (error) { body.classList.remove("loading"); body.textContent=error.message; }
  }
});

function showLoginCard(which) {
  for (const id of ["login-form","forgot-form","reset-form"]) $(id).hidden=id !== which;
}

$("forgot-password").addEventListener("click", () => { $("forgot-username").value=$("username").value; showLoginCard("forgot-form"); });
$("back-to-login").addEventListener("click", () => showLoginCard("login-form"));
$("reset-back-to-login").addEventListener("click", () => showLoginCard("login-form"));
$("forgot-form").addEventListener("submit", async eventObject => {
  eventObject.preventDefault();
  try { const data=await request("forgot-password", {method:"POST", body:{username:$("forgot-username").value.trim().toLowerCase()}}); $("forgot-message").textContent=data.message; }
  catch (error) { $("forgot-message").textContent=error.message; }
});
$("reset-form").addEventListener("submit", async eventObject => {
  eventObject.preventDefault();
  try { const data=await request("reset-password", {method:"POST", body:{username:$("reset-username").value.trim().toLowerCase(), token:$("reset-token").value, password:$("new-password").value}}); $("reset-message").textContent=data.message; setTimeout(() => showLoginCard("login-form"), 1200); }
  catch (error) { $("reset-message").textContent=error.message; }
});

$("connection-rows").addEventListener("click", async eventObject => {
  const button = eventObject.target.closest("[data-test-provider]");
  if (!button) return;
  button.disabled=true; button.textContent="Testing…";
  try { const data=await request("test-connection", {method:"POST", body:{provider:button.dataset.testProvider}}); showToast(`${button.dataset.testProvider} responded in ${data.latency_ms}ms.`); await load(); }
  catch (error) { showToast(error.message, true); }
  finally { button.disabled=false; button.textContent="Test connection"; }
});

$("mapping-form").addEventListener("submit", async eventObject => {
  eventObject.preventDefault();
  try {
    await request("mapping", {method:"POST", body:{id:$("mapping-id").value || undefined, unit_name:$("unit-name").value, unit_id:$("unit-id").value, lock_id:$("lock-id").value, lock_name:$("lock-name").value, gate_access:$("gate-access").checked}});
    $("mapping-dialog").close(); showToast("Access mapping saved."); await load();
  } catch (error) { $("mapping-error").textContent=error.message; }
});

$("mapping-table").addEventListener("click", async eventObject => {
  if (eventObject.target.closest("[data-empty-add]")) openMapping();
  const edit=eventObject.target.closest("[data-edit]");
  const remove=eventObject.target.closest("[data-delete]");
  if (edit) openMapping(snapshot.mappings.find(mapping => mapping.id === edit.dataset.edit));
  if (remove && confirm("Remove this mapping? No provider data will be deleted.")) {
    try { await request("mapping", {method:"DELETE", query:`&id=${encodeURIComponent(remove.dataset.delete)}`}); showToast("Mapping removed."); await load(); }
    catch (error) { showToast(error.message, true); }
  }
});

$("run-sync").addEventListener("click", async eventObject => {
  const button=eventObject.currentTarget; button.disabled=true;
  const outcomes=[];
  for (const action of ["check-guestpoint","sync-ttlock"]) {
    try { await request(action, {method:"POST", body:{}}); outcomes.push(true); }
    catch { outcomes.push(false); }
  }
  await load();
  showToast(outcomes.every(Boolean) ? "GuestPoint and TTLock are synchronised." : outcomes.some(Boolean) ? "Partial sync completed. Check connection setup." : "Sync could not run. Check provider credentials.", !outcomes.some(Boolean));
  button.disabled=false;
});

$("search").addEventListener("input", renderEvents);
$("status-filter").addEventListener("change", renderEvents);
$("provider-filter").addEventListener("change", renderEvents);
$("add-mapping").addEventListener("click", () => openMapping());
$("close-dialog").addEventListener("click", () => $("mapping-dialog").close());
$("cancel-dialog").addEventListener("click", () => $("mapping-dialog").close());
$("close-detail").addEventListener("click", () => { selectedEventId=null; renderEvents(); renderTrace(); });
$("show-all-issues").addEventListener("click", () => { $("status-filter").value="failed"; renderEvents(); $("events").scrollIntoView({behavior:"smooth"}); });
$("logout").addEventListener("click", async () => { try { await request("logout", {method:"POST", body:{}}); } finally { csrf=""; await load(); } });
document.querySelectorAll("[data-section]").forEach(button => button.addEventListener("click", () => { document.querySelectorAll(".nav-item").forEach(item => item.classList.remove("active")); button.classList.add("active"); $(button.dataset.section)?.scrollIntoView({behavior:"smooth", block:"start"}); }));

document.querySelector('[data-section="ai-diagnostic"]').addEventListener("click", () => $("ai-diagnostic").showModal());
const resetParams=new URLSearchParams(location.search);
if (resetParams.get("reset")) { $("reset-token").value=resetParams.get("reset"); $("reset-username").value=resetParams.get("username") || ""; showLoginCard("reset-form"); }

load();
