@extends('vmsopenops::layouts.frontend')

@section('title', 'Operations Statistics')

@section('content')

@include('vholar::pireps.logbook-styles')
<style>
/* Stats page specific */
.lb-period-bar {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 8px;
  padding: 12px 16px;
}
.lb-period-btn {
  font-size: 0.68rem;
  font-weight: 700;
  letter-spacing: 0.07em;
  text-transform: uppercase;
  padding: 4px 12px;
  border-radius: 20px;
  border: 1px solid rgba(255,255,255,0.12);
  background: rgba(255,255,255,0.04);
  color: #9898b0;
  cursor: pointer;
  transition: all 0.15s;
}
.lb-period-btn:hover, .lb-period-btn.active {
  background: rgba(100,80,160,0.3);
  border-color: rgba(120,90,180,0.5);
  color: #c8d8ff;
}
.lb-period-sep {
  font-size: 0.65rem;
  color: #7878a0;
  padding: 0 4px;
}
.lb-period-select {
  font-size: 0.72rem;
  padding: 4px 10px;
  border-radius: 8px;
  border: 1px solid rgba(255,255,255,0.1);
  background: rgba(255,255,255,0.05);
  color: #a0a8c0;
}
.lb-period-select option { background: #1f1c27; }
.lb-period-apply {
  font-size: 0.68rem;
  font-weight: 700;
  letter-spacing: 0.06em;
  text-transform: uppercase;
  padding: 4px 14px;
  border-radius: 8px;
  border: 1px solid rgba(100,80,160,0.4);
  background: rgba(100,80,160,0.15);
  color: #9080c8;
  cursor: pointer;
  transition: all 0.15s;
}
.lb-period-apply:hover { background: rgba(100,80,160,0.3); color: #c8d8ff; }

/* Stats list inside cards */
.lb-stats-list { list-style: none; margin: 0; padding: 0; }
.lb-stats-item {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 9px 16px;
  border-bottom: 1px solid rgba(255,255,255,0.045);
  gap: 10px;
  transition: background 0.12s;
}
.lb-stats-item:last-child { border-bottom: none; }
.lb-stats-item:hover { background: rgba(255,255,255,0.025); }
.lb-stats-rank {
  display: inline-block;
  font-size: 0.65rem;
  font-weight: 700;
  min-width: 22px;
  color: #9090a8;
  flex-shrink: 0;
}
.lb-stats-rank.gold   { color: #d4a017; }
.lb-stats-rank.silver { color: #9aa0b0; }
.lb-stats-rank.bronze { color: #a0714a; }
.lb-stats-label {
  flex: 1;
  min-width: 0;
  font-size: 0.78rem;
  color: #a0a8c0;
}
.lb-stats-label a { color: #8898cc; text-decoration: none; }
.lb-stats-label a:hover { color: #90aaff; }
.lb-stats-label .lb-stats-sub {
  display: block;
  font-size: 0.62rem;
  color: #9090a8;
  margin-top: 1px;
}
.lb-stats-val {
  font-size: 0.88rem;
  font-weight: 800;
  color: #c8d8ff;
  font-variant-numeric: tabular-nums;
  white-space: nowrap;
  flex-shrink: 0;
}
.lb-stats-val.score-good { color: #4caf76; }
.lb-stats-val.score-ok   { color: #e6a817; }
.lb-stats-val.score-bad  { color: #e05060; }

/* Summary grid (right column) */
.lb-summary-grid {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 0;
}
.lb-summary-item {
  padding: 10px 14px;
  border-bottom: 1px solid rgba(255,255,255,0.045);
  border-right: 1px solid rgba(255,255,255,0.045);
}
.lb-summary-item:nth-child(even) { border-right: none; }
.lb-summary-item:nth-last-child(-n+2) { border-bottom: none; }
.lb-summary-key {
  font-size: 0.58rem;
  text-transform: uppercase;
  letter-spacing: 0.08em;
  color: #9090a8;
  margin-bottom: 3px;
}
.lb-summary-val {
  font-size: 0.95rem;
  font-weight: 800;
  color: #c8d8ff;
  font-variant-numeric: tabular-nums;
}
</style>

<div class="row">
  {{-- Left column --}}
  <div class="col-lg-8">

    {{-- Period selector --}}
    <div class="card vholar-logbook-wrap mb-3">
      <div class="lb-month-header">
        <span class="lb-month-title">✈ &nbsp;Operations Statistics</span>
      </div>
      <div class="lb-period-bar">
        <span class="lb-period-sep">Last:</span>
        <button class="lb-period-btn period-btn" data-days="7">Week</button>
        <button class="lb-period-btn period-btn active" data-days="30">Month</button>
        <button class="lb-period-btn period-btn" data-days="90">Quarter</button>
        <button class="lb-period-btn period-btn" data-days="180">Semester</button>
        <button class="lb-period-btn period-btn" data-days="365">Year</button>
        <span class="lb-period-sep" style="margin-left:8px;">or:</span>
        <select id="year-select" class="lb-period-select">
          <option value="">Year…</option>
          @for($y = date('Y'); $y >= 2020; $y--)
            <option value="{{ $y }}">{{ $y }}</option>
          @endfor
        </select>
        <select id="month-select" class="lb-period-select" disabled>
          <option value="">All months</option>
          @for($m = 1; $m <= 12; $m++)
            <option value="{{ $m }}">{{ date('F', mktime(0,0,0,$m,1)) }}</option>
          @endfor
        </select>
        <button id="apply-custom" class="lb-period-apply">Apply</button>
        <button id="apply-year" class="lb-period-apply" style="border-color:rgba(60,80,120,0.4);color:#7090b8;">Full Year</button>
      </div>
    </div>

    {{-- Stats cards container --}}
    <div id="stats-container">
      <div class="text-center py-5 text-muted">
        <div class="spinner-border spinner-border-sm" role="status"></div>
        <p class="mt-2" style="font-size:0.8rem;">Loading statistics…</p>
      </div>
    </div>
  </div>

  {{-- Right column --}}
  <div class="col-lg-4">
    <div id="stats-summary-container">
      <div class="card vholar-logbook-wrap">
        <div class="lb-month-header">
          <span class="lb-month-title">PIREP Statistics</span>
        </div>
        <div class="text-center py-4 text-muted">
          <div class="spinner-border spinner-border-sm" role="status"></div>
          <p class="mt-2" style="font-size:0.8rem;">Loading…</p>
        </div>
      </div>
    </div>
  </div>
</div>

@endsection

@push('scripts')
<script>
$(document).ready(function() {
  let currentPeriod = { days: 30 };

  // Rank decoration
  function rankBadge(index) {
    if (index === 0) return '<span class="lb-stats-rank gold">🥇</span>';
    if (index === 1) return '<span class="lb-stats-rank silver">🥈</span>';
    if (index === 2) return '<span class="lb-stats-rank bronze">🥉</span>';
    return `<span class="lb-stats-rank">${index + 1}.</span>`;
  }

  function scoreClass(score) {
    if (score >= 80) return 'score-good';
    if (score >= 60) return 'score-ok';
    return 'score-bad';
  }

  function statsCard(title, listHtml) {
    return `<div class="col-md-6 mb-3">
      <div class="card vholar-logbook-wrap h-100">
        <div class="lb-month-header"><span class="lb-month-title">${title}</span></div>
        <ul class="lb-stats-list">${listHtml}</ul>
      </div>
    </div>`;
  }

  function emptyItem() {
    return '<li class="lb-stats-item"><span class="lb-stats-label" style="color:#7878a0;">No data</span></li>';
  }

  function pilotCard(title, items, valueKey, labelKey, valueSuffix = '') {
    let rows = items.length === 0 ? emptyItem() : items.map((item, i) =>
      `<li class="lb-stats-item">
        ${rankBadge(i)}
        <span class="lb-stats-label"><a href="/profile/${item.pilot_id}">${item[labelKey]}</a></span>
        <span class="lb-stats-val">${item[valueKey]}${valueSuffix}</span>
      </li>`
    ).join('');
    return statsCard(title, rows);
  }

  function scoreCard(title, items) {
    let rows = items.length === 0 ? emptyItem() : items.map((item, i) =>
      `<li class="lb-stats-item">
        ${rankBadge(i)}
        <span class="lb-stats-label">
          <a href="/profile/${item.pilot_id}">${item.pilot}</a>
          <span class="lb-stats-sub">${item.flight_count} flights</span>
        </span>
        <span class="lb-stats-val ${scoreClass(item.avg_score)}">${item.avg_score}</span>
      </li>`
    ).join('');
    return statsCard(title, rows);
  }

  function routesCard(title, items) {
    let rows = items.length === 0 ? emptyItem() : items.map((item, i) => {
      const parts = item.route.split(' → ');
      return `<li class="lb-stats-item">
        ${rankBadge(i)}
        <span class="lb-stats-label">
          <a href="/airports/${parts[0]}" class="lb-icao" style="font-size:0.88rem;">${parts[0]}</a>
          <span style="color:#3a3a52;margin:0 4px;font-size:0.7rem;">✈</span>
          <a href="/airports/${parts[1]}" class="lb-icao" style="font-size:0.88rem;">${parts[1]}</a>
        </span>
        <span class="lb-stats-val">${item.count}</span>
      </li>`;
    }).join('');
    return statsCard(title, rows);
  }

  function airportCard(title, items, valueKey, airportKey, idKey) {
    let rows = items.length === 0 ? emptyItem() : items.map((item, i) => {
      const txt = item[airportKey];
      const dash = txt.indexOf(' - ');
      const code = dash > 0 ? txt.substring(0, dash) : txt;
      const name = dash > 0 ? txt.substring(dash + 3) : '';
      return `<li class="lb-stats-item">
        ${rankBadge(i)}
        <span class="lb-stats-label">
          <a href="/airports/${item[idKey]}" class="lb-icao" style="font-size:0.88rem;">${code}</a>
          ${name ? `<span class="lb-stats-sub">${name}</span>` : ''}
        </span>
        <span class="lb-stats-val">${item[valueKey]}</span>
      </li>`;
    }).join('');
    return statsCard(title, rows);
  }

  function subfleetCard(title, items) {
    let rows = items.length === 0 ? emptyItem() : items.map((item, i) =>
      `<li class="lb-stats-item">
        ${rankBadge(i)}
        <span class="lb-stats-label"><a href="/dfleet/${item.subfleet_type}">${item.subfleet}</a></span>
        <span class="lb-stats-val">${item.flight_count}</span>
      </li>`
    ).join('');
    return statsCard(title, rows);
  }

  function aircraftCard(title, items, valueKey) {
    let rows = items.length === 0 ? emptyItem() : items.map((item, i) =>
      `<li class="lb-stats-item">
        ${rankBadge(i)}
        <span class="lb-stats-label">
          <a href="/daircraft/${item.aircraft_registration}">
            <span class="lb-ac-type">${item.aircraft_type}</span>
            <span style="margin-left:4px;font-size:0.78rem;color:#a0a8c0;">${item.aircraft}</span>
          </a>
        </span>
        <span class="lb-stats-val">${item[valueKey]}</span>
      </li>`
    ).join('');
    return statsCard(title, rows);
  }

  function renderTops(data) {
    let html = '<div class="row">';
    html += scoreCard('Top Score', data.top_score);
    html += pilotCard('Top Landing Score', data.top_landing_score, 'avg_landing_rate', 'pilot', ' ft/m');
    html += pilotCard('Most Miles Flown', data.top_miles, 'total_distance', 'pilot', ' nm');
    html += pilotCard('Most Flights', data.top_flights_count, 'flight_count', 'pilot');
    html += routesCard('Top Routes', data.top_routes);
    html += subfleetCard('Top Subfleets', data.top_subfleets);
    html += aircraftCard('Top Aircraft (flights)', data.top_aircraft_flights, 'flight_count');
    html += aircraftCard('Top Aircraft (miles)', data.top_aircraft_miles, 'total_distance');
    html += airportCard('Top Arrival Airports', data.top_arr_airports, 'arrival_count', 'airport', 'airport_id');
    html += airportCard('Top Departure Airports', data.top_dpt_airports, 'departure_count', 'airport', 'airport_id');
    html += '</div>';
    $('#stats-container').html(html);
  }

  function renderStatsSummary(data) {
    const s = data.stats;
    const items = [
      ['PIREPs (Accepted)', s.total_pireps.toLocaleString()],
      ['Avg Score', s.avg_score],
      ['Total Block Time', s.total_block_time],
      ['Avg Block Time', s.avg_block_time],
      ['Total Distance', s.total_distance.toLocaleString() + ' nm'],
      ['Avg Distance', s.avg_distance.toLocaleString() + ' nm'],
      ['Avg Distance/hr', s.avg_distance_per_hour.toLocaleString() + ' nm'],
      ['Avg Landing Rate', s.avg_landing_rate.toLocaleString() + ' ft/m'],
      ['Total Passengers', s.total_passengers.toLocaleString()],
      ['Avg Passengers', s.avg_passengers.toLocaleString()],
      ['Total Fuel Burn', s.total_fuel_burn.toLocaleString() + ' kg'],
      ['Avg Fuel Burn/hr', s.avg_fuel_burn_per_hour.toLocaleString() + ' kg'],
    ];
    const gridHtml = items.map(([key, val]) =>
      `<div class="lb-summary-item">
        <div class="lb-summary-key">${key}</div>
        <div class="lb-summary-val">${val}</div>
      </div>`
    ).join('');

    $('#stats-summary-container').html(`
      <div class="card vholar-logbook-wrap">
        <div class="lb-month-header"><span class="lb-month-title">PIREP Statistics</span></div>
        <div class="lb-summary-grid">${gridHtml}</div>
      </div>`);
  }

  function loadStats() {
    const spinner = '<div class="text-center py-4 text-muted"><div class="spinner-border spinner-border-sm" role="status"></div><p class="mt-2" style="font-size:0.8rem;">Loading…</p></div>';
    $('#stats-container').html(spinner);
    $('#stats-summary-container').html(`<div class="card vholar-logbook-wrap"><div class="lb-month-header"><span class="lb-month-title">PIREP Statistics</span></div>${spinner}</div>`);

    let params = {};
    if (currentPeriod.days)  params.days = currentPeriod.days;
    else if (currentPeriod.year && currentPeriod.month) { params.year = currentPeriod.year; params.month = currentPeriod.month; }
    else if (currentPeriod.year) params.year = currentPeriod.year;

    $.ajax({
      url: '{{ route("api.vmsopenops.api.stats") }}',
      method: 'GET',
      data: params,
      success: function(res) {
        if (res.success) { renderTops(res.data); renderStatsSummary(res.data); }
        else {
          $('#stats-container').html('<div class="p-3 text-danger" style="font-size:0.8rem;">Error loading stats</div>');
          $('#stats-summary-container').html('<div class="p-3 text-danger" style="font-size:0.8rem;">Error loading stats</div>');
        }
      },
      error: function() {
        $('#stats-container').html('<div class="p-3 text-danger" style="font-size:0.8rem;">Connection error</div>');
      }
    });
  }

  $('.period-btn').click(function() {
    $('.period-btn').removeClass('active');
    $(this).addClass('active');
    currentPeriod = { days: $(this).data('days') };
    $('#year-select').val('');
    $('#month-select').val('').prop('disabled', true);
    loadStats();
  });

  $('#year-select').change(function() {
    $('#month-select').prop('disabled', !$(this).val());
    if (!$(this).val()) $('#month-select').val('');
  });

  $('#apply-custom').click(function() {
    const year = $('#year-select').val(), month = $('#month-select').val();
    if (year && month) { currentPeriod = { year, month }; $('.period-btn').removeClass('active'); loadStats(); }
    else if (year) alert('Select a month or use Full Year');
    else alert('Select a year first');
  });

  $('#apply-year').click(function() {
    const year = $('#year-select').val();
    if (year) { currentPeriod = { year, month: null }; $('.period-btn').removeClass('active'); $('#month-select').val(''); loadStats(); }
    else alert('Select a year first');
  });

  loadStats();
});
</script>
@endpush
