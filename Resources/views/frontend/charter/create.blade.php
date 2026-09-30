@extends('vmsopenops::layouts.frontend')

@section('title', 'Create Charter Flight')

@section('content')
<div class="card">
    <div class="card-body">
        <h1>Create Charter Flight</h1>
        @include('flash::message')
        
        <div class="alert alert-info">
            <i class="fas fa-info-circle"></i> 
            Your current location: 
            <strong>
                {{ $user->curr_airport_id }} - 
                @php
                    $currentAirport = \App\Models\Airport::find($user->curr_airport_id);
                @endphp
                {{ $currentAirport ? $currentAirport->name : 'Unknown' }}
            </strong>
            <br>
            <i class="fas fa-tag"></i> 
            Your Pilot ID: <strong>{{ $user->id }}</strong> (used as flight number)
        </div>
        
        <form method="POST" action="{{ route('vmsopenops.charter.store') }}" id="charterForm">
            {{ csrf_field() }}
            
            <div class="row">
                <div class="col-md-6">
                    <div class="form-group">
                        <label>Flight Type *</label>
                        <select name="route_code" id="route_code" class="form-control" required>
                            <option value="">Select flight type...</option>
                            @foreach($flightTypes as $code => $info)
                                <option value="{{ $code }}" data-flight-type="{{ $info['type'] }}">
                                    {{ $code }} - {{ $info['name'] }}
                                </option>
                            @endforeach
                        </select>
                        <input type="hidden" name="flight_type" id="flight_type">
                        <small class="text-muted">The callsign will be: Pilot ID + Route Code (ej: {{ $user->id }}CH)</small>
                    </div>
                </div>
            </div>
            
            <div class="row">
                <div class="col-md-6">
                    <div class="form-group">
                        <label>Scheduled Departure (UTC) *</label>
                        <input type="datetime-local" name="scheduled_departure" id="scheduled_departure" class="form-control" required>
                        <small class="text-muted">Select the departure date and time in UTC. Default is current UTC time + 30 minutes.</small>
                    </div>
                </div>
            </div>
            
            <div class="row">
                <div class="col-md-6">
                    <div class="form-group">
                        <label>Destination Airport *</label>
                        <select name="to_airport_id" id="to_airport_id" class="form-control airport-select" required>
                            <option value="">Search for destination airport...</option>
                        </select>
                    </div>
                </div>
            </div>
            
            <div class="row">
                <div class="col-md-6">
                    <div class="form-group">
                        <label>Aircraft *</label>
                        <select name="aircraft_id" id="aircraft_id" class="form-control" required>
                            <option value="">Select an aircraft...</option>
                            @foreach($aircraft as $ac)
                                <option value="{{ $ac->id }}" data-subfleet="{{ $ac->subfleet_id }}">
                                    {{ $ac->registration }} - {{ $ac->name }} ({{ $ac->subfleet->name }})
                                </option>
                            @endforeach
                        </select>
                    </div>
                </div>
            </div>
            
            <div class="row">
                <div class="col-md-6">
                    <div class="form-group">
                        <label>Cruise Altitude (optional)</label>
                        <input type="number" name="level" class="form-control" placeholder="e.g., 35000 ft (1000-50000)">
                        <small class="text-muted">Leave empty for random altitude between 28,000-41,000 ft</small>
                    </div>
                </div>
            </div>
            
            <!-- Preview Panel -->
            <div id="previewPanel" style="display: none;" class="mt-3">
                <div class="alert alert-info">
                    <h5>Flight Preview</h5>
                    <hr>
                    <div class="row">
                        <div class="col-md-6">
                            <strong>Distance:</strong> <span id="distanceDisplay">0</span> NM
                        </div>
                        <div class="col-md-6">
                            <strong>Flight Time:</strong> <span id="flightTimeDisplay">0</span>
                        </div>
                    </div>
                    <div class="row mt-2">
                        <div class="col-md-12">
                            <strong>Departure:</strong> <span id="departureDisplay"></span> UTC
                        </div>
                    </div>
                    <div id="sameAirportWarning" class="alert alert-warning mt-2" style="display: none;">
                        <i class="fas fa-exclamation-triangle"></i> Destination is the same as your current location!
                    </div>
                </div>
            </div>
            
            <div class="form-group mt-3">
                <button type="submit" class="btn btn-primary" id="submitBtn">
                    <i class="fas fa-paper-plane"></i> Create Charter Flight
                </button>
                <a href="{{ route('frontend.dashboard.index') }}" class="btn btn-secondary">Cancel</a>
            </div>
        </form>
    </div>
</div>
@endsection

@section('scripts')
<link href="https://cdnjs.cloudflare.com/ajax/libs/select2/4.0.13/css/select2.min.css" rel="stylesheet" />
<script src="https://cdnjs.cloudflare.com/ajax/libs/select2/4.0.13/js/select2.min.js"></script>

<style id="select2-dark-override">
.select2-container--default .select2-selection--single {
    background-color: #1e1e2e !important;
    border: 1px solid #4a4a6a !important;
}
.select2-container--default .select2-selection--single .select2-selection__rendered {
    color: #e0e0e0 !important;
}
.select2-container--default .select2-selection--single .select2-selection__arrow b {
    border-color: #aaa transparent transparent transparent !important;
}
.select2-dropdown {
    background-color: #1e1e2e !important;
    border: 1px solid #4a4a6a !important;
    color: #e0e0e0 !important;
}
.select2-search--dropdown {
    background-color: #1e1e2e !important;
}
.select2-search--dropdown .select2-search__field {
    background-color: #12121f !important;
    border: 1px solid #4a4a6a !important;
    color: #e0e0e0 !important;
    caret-color: #e0e0e0 !important;
}
.select2-results__options {
    background-color: #1e1e2e !important;
}
.select2-results__option {
    color: #c0c0d0 !important;
    background-color: #1e1e2e !important;
}
.select2-results__option--highlighted,
.select2-results__option--highlighted[aria-selected],
.select2-results__option--highlighted[aria-selected="false"] {
    background-color: #1a5276 !important;
    color: #ffffff !important;
}
.select2-results__option[aria-selected="true"] {
    background-color: #154360 !important;
    color: #85c1e9 !important;
}
</style>

<script>
$(document).ready(function () {
    let currentPreviewData = null;
    
    // Set default scheduled departure (UTC now + 30 minutes)
    function setDefaultScheduledDeparture() {
        const now = new Date();
        const defaultTime = new Date(now.getTime() + 30 * 60000); // +30 minutos
        const year = defaultTime.getUTCFullYear();
        const month = String(defaultTime.getUTCMonth() + 1).padStart(2, '0');
        const day = String(defaultTime.getUTCDate()).padStart(2, '0');
        const hours = String(defaultTime.getUTCHours()).padStart(2, '0');
        const minutes = String(defaultTime.getUTCMinutes()).padStart(2, '0');
        const formattedDateTime = `${year}-${month}-${day}T${hours}:${minutes}`;
        $('#scheduled_departure').val(formattedDateTime);
        updateDepartureDisplay();
    }
    
    // Update departure display in preview
    function updateDepartureDisplay() {
        const departureValue = $('#scheduled_departure').val();
        if (departureValue) {
            const formatted = departureValue.replace('T', ' ');
            $('#departureDisplay').text(formatted);
        } else {
            const now = new Date();
            const defaultTime = new Date(now.getTime() + 30 * 60000);
            const formatted = defaultTime.toISOString().slice(0, 16).replace('T', ' ');
            $('#departureDisplay').text(formatted);
        }
    }
    
    // Set flight type when route code changes
    $('#route_code').on('change', function() {
        const selected = $(this).find(':selected');
        const flightType = selected.data('flight-type');
        $('#flight_type').val(flightType);
        
        // Clear preview when flight type changes
        $('#previewPanel').hide();
    });
    
    // Initialize Select2 for airport search
    $('#to_airport_id').select2({
        ajax: {
            url: '/api/airports/search',
            dataType: 'json',
            delay: 250,
            data: function(params) {
                return {
                    search: params.term,
                    page: params.page || 1,
                    orderBy: 'id',
                    sortedBy: 'asc'
                };
            },
            processResults: function(data) {
                if (!data.data) return { results: [] };
                return {
                    results: data.data.map(function(airport) {
                        return {
                            id: airport.id,
                            text: airport.description
                        };
                    })
                };
            },
            cache: true
        },
        placeholder: 'Search for destination airport...',
        minimumInputLength: 2,
        width: '100%'
    });
    
    // Preview when destination, aircraft, or departure changes
    let debounceTimer;
    $('#to_airport_id, #aircraft_id, #scheduled_departure').on('change', function() {
        clearTimeout(debounceTimer);
        debounceTimer = setTimeout(function() {
            const airportId = $('#to_airport_id').val();
            const aircraftId = $('#aircraft_id').val();
            const routeCode = $('#route_code').val();
            
            if (airportId && aircraftId && routeCode) {
                previewFlight(airportId, aircraftId);
            } else {
                $('#previewPanel').hide();
            }
        }, 300);
    });
    
    function previewFlight(airportId, aircraftId) {
        $.ajax({
            url: '{{ route("vmsopenops.charter.preview") }}',
            method: 'POST',
            data: {
                to_airport_id: airportId,
                aircraft_id: aircraftId,
                _token: '{{ csrf_token() }}'
            },
            success: function(response) {
                if (response.success) {
                    currentPreviewData = response.data;
                    $('#distanceDisplay').text(response.data.distance.value.toFixed(2));
                    $('#flightTimeDisplay').text(response.data.flight_time.formatted);
                    updateDepartureDisplay();
                    $('#previewPanel').show();
                }
            },
            error: function(xhr) {
                console.error('Preview error:', xhr);
                $('#previewPanel').hide();
            }
        });
    }
    
    // Update departure display when date changes
    $('#scheduled_departure').on('change', function() {
        updateDepartureDisplay();
    });
    
    // Form validation
    $('#charterForm').on('submit', function(e) {
        const routeCode = $('#route_code').val();
        if (!routeCode) {
            e.preventDefault();
            alert('Please select a flight type.');
            return false;
        }
        
        const scheduledDeparture = $('#scheduled_departure').val();
        if (!scheduledDeparture) {
            e.preventDefault();
            alert('Please select a scheduled departure date and time.');
            return false;
        }
        
        const airportId = $('#to_airport_id').val();
        if (!airportId) {
            e.preventDefault();
            alert('Please select a destination airport.');
            return false;
        }
        
        const aircraftId = $('#aircraft_id').val();
        if (!aircraftId) {
            e.preventDefault();
            alert('Please select an aircraft.');
            return false;
        }
        
        return true;
    });
    
    // Set default value on page load
    setDefaultScheduledDeparture();
});
</script>
@endsection