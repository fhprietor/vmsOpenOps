@extends('vmsopenops::layouts.frontend')

@section('title', 'New Ferry Request')

@section('content')
<div class="card">
    <div class="card-body">
        <h1>New Ferry Request</h1>
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
            <i class="fas fa-dollar-sign"></i> 
            Your current balance: 
            <strong>{{ $balance->money->format() ?? '$0.00' }}</strong>
        </div>
        
        <form method="POST" action="{{ route('vmsopenops.ferry.store') }}" id="ferryForm">
            {{ csrf_field() }}
            
            <div class="form-group">
                <label>Aircraft Type</label>
                <select name="subfleet_id" id="subfleet_id" class="form-control" required>
                    <option value="">Select aircraft type...</option>
                    @foreach($subfleets as $subfleet)
                        <option value="{{ $subfleet->id }}">{{ $subfleet->name }}</option>
                    @endforeach
                </select>
            </div>
            
            <div class="form-group" id="aircraftGroup" style="display: none;">
                <label>Aircraft</label>
                <select name="aircraft_id" id="aircraft_id" class="form-control" required>
                    <option value="">Select an aircraft...</option>
                </select>
            </div>
            
            <div id="previewPanel" style="display: none;">
                <div class="alert alert-info">
                    <h5>Ferry Preview</h5>
                    <hr>
                    <div class="row">
                        <div class="col-md-6">
                            <strong>Distance:</strong> <span id="distanceDisplay">0</span>
                        </div>
                        <div class="col-md-6">
                            <strong>Cost:</strong> <span id="costDisplay">$0.00</span>
                        </div>
                    </div>
                    <div id="sameAirportWarning" class="alert alert-warning mt-2" style="display: none;">
                        <i class="fas fa-exclamation-triangle"></i> This aircraft is already at your airport!
                    </div>
                    <div id="insufficientFundsWarning" class="alert alert-danger mt-2" style="display: none;">
                        <i class="fas fa-exclamation-circle"></i> Insufficient funds for immediate ferry.
                    </div>
                </div>
            </div>
            
            <div class="form-group">
                <div class="form-check">
                    <input type="radio" name="type" id="typeRequest" value="0" class="form-check-input">
                    <label class="form-check-label" for="typeRequest">
                        Submit for Approval (Admin review required)
                    </label>
                </div>
                <div class="form-check">
                    <input type="radio" name="type" id="typeImmediate" value="1" class="form-check-input" checked>
                    <label class="form-check-label" for="typeImmediate">
                        Pay Immediately (<span id="immediateCostLabel">$0.00</span>)
                    </label>
                </div>
            </div>
            
            @if($requireReason)
            <div class="form-group">
                <label>Reason *</label>
                <textarea name="reason" class="form-control" rows="3" maxlength="{{ $maxReasonLength }}" placeholder="Why do you need to ferry this aircraft?"></textarea>
                <small class="text-muted">Maximum {{ $maxReasonLength }} characters</small>
            </div>
            @else
            <div class="form-group">
                <label>Reason (Optional)</label>
                <textarea name="reason" class="form-control" rows="3" maxlength="{{ $maxReasonLength }}" placeholder="Optional reason..."></textarea>
                <small class="text-muted">Maximum {{ $maxReasonLength }} characters</small>
            </div>
            @endif
            
            <button type="submit" class="btn btn-primary" id="submitBtn">
                <i class="fas fa-paper-plane"></i> Submit Request
            </button>
            <a href="{{ route('vmsopenops.ferry.index') }}" class="btn btn-secondary">Cancel</a>
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
    let selectedAircraftId = null;
    
    // Inicializar Select2 para tipo de aeronave
    $('#subfleet_id').select2({
        width: '100%',
        placeholder: 'Select aircraft type...',
        allowClear: true
    }).on('change', function() {
        const subfleetId = $(this).val();
        if (subfleetId) {
            loadAircraft(subfleetId);
        } else {
            $('#aircraftGroup').hide();
            $('#aircraft_id').empty();
            $('#previewPanel').hide();
        }
    });
    
    function loadAircraft(subfleetId) {
        $('#aircraft_id').html('<option value="">Loading aircraft...</option>');
        $('#aircraftGroup').show();
        
        $.ajax({
            url: '/api/vmsopenops/ferry/available',
            method: 'POST',
            data: {
                subfleet_id: subfleetId,
                _token: '{{ csrf_token() }}'
            },
            success: function(response) {
                if (response.success && response.aircraft.length > 0) {
                    const aircraftSelect = $('#aircraft_id');
                    aircraftSelect.empty();
                    aircraftSelect.append('<option value="">Select an aircraft...</option>');
                    
                    response.aircraft.forEach(function(ac) {
                        aircraftSelect.append(
                            `<option value="${ac.id}" 
                                data-distance="${ac.distance}" 
                                data-cost="${ac.cost}" 
                                data-cost-formatted="${ac.cost_formatted}"
                                data-registration="${ac.registration}"
                                data-current-airport="${ac.current_airport}">
                                ${ac.registration} - ${ac.name} (${ac.current_airport}, ${ac.distance.toFixed(2)} NM)
                            </option>`
                        );
                    });
                    
                    // Inicializar Select2 para aeronaves
                    aircraftSelect.select2({
                        width: '100%',
                        placeholder: 'Select an aircraft...',
                        allowClear: true
                    }).on('change', function() {
                        const selected = $(this).find(':selected');
                        if (selected.val()) {
                            previewFerry(selected);
                        } else {
                            $('#previewPanel').hide();
                            selectedAircraftId = null;
                        }
                    });
                } else {
                    $('#aircraft_id').html('<option value="">No aircraft available</option>');
                    $('#previewPanel').hide();
                    if (response.message) {
                        alert(response.message);
                    }
                }
            },
            error: function(xhr) {
                console.error('AJAX ERROR DETAILS:');
                console.error('Status:', xhr.status);
                console.error('Status Text:', xhr.statusText);
                console.error('Response:', xhr.responseText);
                
                let errorMessage = 'Error loading aircraft. ';
                if (xhr.responseJSON && xhr.responseJSON.message) {
                    errorMessage += xhr.responseJSON.message;
                } else if (xhr.responseText) {
                    errorMessage += xhr.responseText;
                }
                
                $('#aircraft_id').html('<option value="">Error loading aircraft</option>');
                $('#previewPanel').hide();
                alert(errorMessage);
            }
        });
    }
    
    function previewFerry(selectedOption) {
        const aircraftId = selectedOption.val();
        const registration = selectedOption.data('registration');
        const currentAirport = selectedOption.data('current-airport');
        const distance = selectedOption.data('distance');
        const costFormatted = selectedOption.data('cost-formatted');
        
        $.ajax({
            url: '{{ route("api.vmsopenops.api.ferry.preview") }}',
            method: 'POST',
            data: {
                aircraft_id: aircraftId,
                _token: '{{ csrf_token() }}'
            },
            success: function(response) {
                if (response.success) {
                    currentPreviewData = response.data;
                    selectedAircraftId = aircraftId;
                    $('#distanceDisplay').text(response.data.distance.value.toFixed(2) + ' NM');
                    $('#costDisplay').text('$' + (response.data.cost.value).toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2}));
                    $('#previewPanel').show();
                    
                    if (response.data.is_same_airport) {
                        $('#sameAirportWarning').show();
                        $('#insufficientFundsWarning').hide();
                        $('#submitBtn').prop('disabled', true);
                    } else {
                        $('#sameAirportWarning').hide();
                        $('#submitBtn').prop('disabled', false);
                        
                        if (!response.data.can_pay_immediately && $('#typeImmediate').is(':checked')) {
                            $('#insufficientFundsWarning').show();
                        } else {
                            $('#insufficientFundsWarning').hide();
                        }
                    }
                    updateImmediateCost();
                }
            },
            error: function(xhr) {
                console.error('Preview error:', xhr);
                $('#previewPanel').hide();
            }
        });
    }
    
    function updateImmediateCost() {
        if (currentPreviewData && $('#typeImmediate').is(':checked')) {
            $('#immediateCostLabel').text('$' + (currentPreviewData.cost.value).toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2}));
        }
    }
    
    $('input[name="type"]').on('change', function() {
        updateImmediateCost();
        if ($('#typeImmediate').is(':checked') && currentPreviewData && !currentPreviewData.can_pay_immediately && !currentPreviewData.is_same_airport) {
            $('#insufficientFundsWarning').show();
        } else {
            $('#insufficientFundsWarning').hide();
        }
    });
    
    $('#ferryForm').on('submit', function(e) {
        const aircraftId = $('#aircraft_id').val();
        if (!aircraftId) {
            e.preventDefault();
            alert('Please select an aircraft to ferry.');
            return false;
        }
        
        @if($requireReason)
        const reason = $('textarea[name="reason"]').val().trim();
        if (!reason) {
            e.preventDefault();
            alert('Please provide a reason for this ferry request.');
            return false;
        }
        @endif
        
        return true;
    });
});
</script>
@endsection