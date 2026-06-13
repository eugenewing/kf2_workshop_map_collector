const state = {
  activeType: "maps",
  query: "",
  running: false,
  pollingTimer: null,
};

const els = {
  status: document.querySelector("[data-role=status]"),
  refresh: document.querySelector("[data-action=refresh]"),
  reset: document.querySelector("[data-action=reset]"),
  search: document.querySelector("[data-role=search]"),
  delay: document.querySelector("[data-role=delay]"),
  maxPages: document.querySelector("[data-role=max-pages]"),
  list: document.querySelector("[data-role=list]"),
  progress: {
    wrap: document.querySelector("[data-role=progress-wrap]"),
    phase: document.querySelector("[data-role=progress-phase]"),
    percent: document.querySelector("[data-role=progress-percent]"),
    fill: document.querySelector("[data-role=progress-fill]"),
    track: document.querySelector(".progress-track"),
  },
  tabs: [...document.querySelectorAll("[data-type]")],
  counts: {
    total: document.querySelector("[data-role=count-total]"),
    maps: document.querySelector("[data-role=count-maps]"),
    review: document.querySelector("[data-role=count-review]"),
    pages: document.querySelector("[data-role=count-pages]"),
    lastRun: document.querySelector("[data-role=last-run]"),
  },
};

function setStatus(text, tone = "default") {
  els.status.textContent = text;
  els.status.className = tone === "default" ? "status" : `status ${tone}`;
}

function clampPercent(value) {
  if (!Number.isFinite(value)) {
    return 0;
  }
  return Math.max(0, Math.min(100, Math.round(value)));
}

function phaseLabel(phase) {
  const labels = {
    init: "Preparing",
    browse: "Reading workshop pages",
    details: "Loading item details",
    classify: "Classifying KF2 maps",
    done: "Done",
    error: "Failed",
  };

  if (!phase) {
    return "Idle";
  }

  return labels[phase] || phase;
}

function progressPercent(data) {
  const phase = data.phase || "";
  const pages = Number(data.browse_pages_processed || 0);
  const totalItems = Number(data.workshop_total_items || 0);
  const analyzed = Number(data.detailed_items_analyzed || 0);
  const pageLimit = Number(data.browse_pages_limit || 0);

  if (phase === "browse") {
    const estimatedPages = pageLimit > 0
      ? pageLimit
      : (totalItems > 0 ? Math.max(1, Math.ceil(totalItems / 30)) : Math.max(1, pages));
    return clampPercent((pages / estimatedPages) * 35);
  }

  if (phase === "details") {
    const detailsPart = totalItems > 0 ? (analyzed / totalItems) * 45 : 0;
    return clampPercent(35 + detailsPart);
  }

  if (phase === "classify") {
    const classifyPart = totalItems > 0 ? (analyzed / totalItems) * 20 : 20;
    return clampPercent(80 + classifyPart);
  }

  if (phase === "done") {
    return 100;
  }

  return state.running ? 5 : 0;
}

function renderProgress(data) {
  if (!state.running) {
    els.progress.wrap.hidden = true;
    els.progress.fill.style.width = "0%";
    els.progress.percent.textContent = "0%";
    els.progress.phase.textContent = "Phase: idle";
    els.progress.track.setAttribute("aria-valuenow", "0");
    return;
  }

  const percent = progressPercent(data);
  const phase = phaseLabel(data.phase || "");

  els.progress.wrap.hidden = false;
  els.progress.fill.style.width = `${percent}%`;
  els.progress.percent.textContent = `${percent}%`;
  els.progress.phase.textContent = `Phase: ${phase}`;
  els.progress.track.setAttribute("aria-valuenow", String(percent));
}

function clearPolling() {
  if (state.pollingTimer !== null) {
    clearTimeout(state.pollingTimer);
    state.pollingTimer = null;
  }
}

function scheduleStatePolling() {
  clearPolling();

  if (!state.running) {
    return;
  }

  state.pollingTimer = window.setTimeout(async () => {
    try {
      await loadState({ keepMessage: true });
      if (state.running) {
        await loadList();
      }
    } catch (error) {
      setStatus(error.message, "error");
      setRunning(false);
      return;
    }

    scheduleStatePolling();
  }, 1500);
}

function setRunning(running) {
  state.running = running;
  els.refresh.disabled = running;
  els.reset.disabled = running;
  els.refresh.textContent = running ? "Updating..." : "Refresh Workshop Data";

  if (running) {
    scheduleStatePolling();
  } else {
    clearPolling();
  }
}

async function fetchJson(url, options = {}) {
  const response = await fetch(url, options);
  const data = await response.json();
  if (!response.ok) {
    throw new Error(data.error || "Request failed.");
  }
  return data;
}

function formatDate(value) {
  if (!value) return "Never";
  const date = new Date(value);
  return Number.isNaN(date.getTime()) ? value : date.toLocaleString();
}

function runningMessage(data) {
  const maps = data.maps_count || 0;
  const analyzed = data.detailed_items_analyzed || 0;
  const total = data.workshop_total_items || 0;
  const pages = data.browse_pages_processed || 0;
  const phase = data.phase || "";
  const appliedLimit = data.browse_pages_limit || 0;
  const requestedLimit = data.requested_max_browse_pages;

  let message = `Scan in progress: KF2 maps found ${maps}. Items analyzed ${analyzed}`;

  if (total > 0) {
    message += ` of ${total}`;
  }

  if (appliedLimit > 0) {
    message += `. Workshop pages: ${pages}/${appliedLimit}`;
  } else {
    message += `. Workshop pages: ${pages}`;
  }

  if (requestedLimit !== null && requestedLimit !== "") {
    message += `. Requested limit: ${requestedLimit}`;
  }

  if (phase) {
    message += `. Phase: ${phase}`;
  }

  return message;
}

function renderState(payload, { keepMessage = false } = {}) {
  const data = payload.state || {};
  els.counts.total.textContent = data.workshop_total_items || 0;
  els.counts.maps.textContent = data.maps_count || 0;
  els.counts.review.textContent = data.review_count || 0;
  els.counts.pages.textContent = data.browse_pages_processed || 0;
  els.counts.lastRun.textContent = formatDate(data.last_run_at);

  if (data.status === "running") {
    setRunning(true);
    renderProgress(data);
    setStatus(runningMessage(data), "default");
    return;
  }

  setRunning(false);
  renderProgress(data);

  if (data.status === "error") {
    if (!keepMessage) {
      setStatus(`Last run failed: ${data.error || "Unknown error"}`, "error");
    }
    return;
  }

  if (data.last_run_at) {
    if (!keepMessage) {
      setStatus("Data loaded from JSON storage.", "ok");
    }
    return;
  }

  if (!keepMessage) {
    setStatus("No data yet. Start the first scan to build the JSON files.", "default");
  }
}

function renderItems(items) {
  if (!items.length) {
    els.list.innerHTML = '<div class="empty">Nothing found for the current tab and search query.</div>';
    return;
  }

  els.list.innerHTML = items.map((item) => {
    const link = `https://steamcommunity.com/sharedfiles/filedetails/?id=${encodeURIComponent(item.id)}`;
    return `
      <div class="list-item">
        <div class="map-name">${escapeHtml(item.name)}</div>
        <div class="map-id">${escapeHtml(item.id)}</div>
        <div class="map-link"><a href="${link}" target="_blank" rel="noreferrer">Open</a></div>
      </div>
    `;
  }).join("");
}

function escapeHtml(value) {
  return String(value)
    .replaceAll("&", "&amp;")
    .replaceAll("<", "&lt;")
    .replaceAll(">", "&gt;")
    .replaceAll('"', "&quot;")
    .replaceAll("'", "&#39;");
}

async function loadState(options = {}) {
  const payload = await fetchJson("./api/state.php");
  renderState(payload, options);
}

async function loadList() {
  const params = new URLSearchParams({
    type: state.activeType,
    q: state.query,
  });

  const payload = await fetchJson(`./api/maps.php?${params.toString()}`);
  renderItems(payload.items || []);
}

function selectTab(type) {
  state.activeType = type;
  for (const tab of els.tabs) {
    tab.classList.toggle("active", tab.dataset.type === type);
  }
  loadList().catch((error) => setStatus(error.message, "error"));
}

async function refreshWorkshopData() {
  setRunning(true);
  renderProgress({ phase: "init" });
  setStatus("Workshop scan started. Tracking progress...", "default");

  const delayValue = els.delay.value || "40";
  const maxPagesValue = (els.maxPages.value || "").trim();
  const params = new URLSearchParams();
  params.set("delay_ms", delayValue);

  if (maxPagesValue !== "") {
    params.set("max_browse_pages", maxPagesValue);
  }

  const query = new URLSearchParams();
  if (maxPagesValue !== "") {
    query.set("max_browse_pages", maxPagesValue);
  }

  const refreshUrl = query.toString() ? `./api/refresh.php?${query.toString()}` : "./api/refresh.php";

  try {
    const headers = {
      "Content-Type": "application/x-www-form-urlencoded;charset=UTF-8",
    };

    if (maxPagesValue !== "") {
      headers["X-KF2-WSMC-Max-Pages"] = maxPagesValue;
    }

    await fetchJson(refreshUrl, {
      method: "POST",
      headers,
      body: params.toString(),
    });

    await loadState();
    await loadList();
    setStatus("Workshop data refreshed successfully.", "ok");
  } catch (error) {
    setStatus(error.message, "error");
    setRunning(false);
    renderProgress({});
  }
}

async function resetWorkshopData() {
  if (!window.confirm("Reset parsed data (maps, review, and state)?")) {
    return;
  }

  setStatus("Resetting parsed data...", "default");
  els.reset.disabled = true;

  try {
    await fetchJson("./api/reset.php", {
      method: "POST",
    });

    state.query = "";
    els.search.value = "";
    await loadState();
    await loadList();
    setStatus("Parsed data has been reset.", "ok");
  } catch (error) {
    setStatus(error.message, "error");
  } finally {
    els.reset.disabled = state.running;
  }
}

els.refresh.addEventListener("click", () => {
  refreshWorkshopData();
});

els.reset.addEventListener("click", () => {
  resetWorkshopData();
});

els.search.addEventListener("input", (event) => {
  state.query = event.target.value.trim();
  loadList().catch((error) => setStatus(error.message, "error"));
});

for (const tab of els.tabs) {
  tab.addEventListener("click", () => selectTab(tab.dataset.type));
}

Promise.all([loadState(), loadList()]).catch((error) => {
  setStatus(error.message, "error");
});
