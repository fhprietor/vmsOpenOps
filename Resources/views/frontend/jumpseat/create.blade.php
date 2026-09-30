@extends('vmsopenops::layouts.frontend')

@section('title', 'New Jumpseat')

@section('content')
<div class="card">
    <div class="card-body">
        <h1>New Jumpseat</h1>
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
            <strong>{{ $user->journal->balance->money->format() ?? '$0.00' }}</strong>
        </div>
        
        <form method="POST" action="{{ route('vmsopenops.jumpseat.store') }}">
            {{ csrf_field() }}
            
            <div class="form-group">
                <div class="form-check">
                    <input class="" type="radio" name="type" value="0" id="typeRequest">
                    <label class="form-check-label" for="typeRequest">
                        Make Jumpseat Request
                    </label>
                </div>
                <div class="form-check">
                    <input class="" type="radio" name="type" id="typeImmediate" value="1" checked>
                    <label class="form-check-label" for="typeImmediate">
                        Pay for Immediate Jumpseat (<span id="immediateCostLabel">$0.00</span>)
                    </label>
                </div>
            </div>
            
            <div class="row">
                <div class="col-6">
                    <label>Airport</label>
                    <div class="form-group">
                        <select class="custom-select airport_search" name="to_airport_id" required></select>
                    </div>
                </div>
            </div>
            
            <!-- Preview Panel -->
            <div id="previewPanel" style="display: none;">
                <div class="alert alert-info">
                    <h5>Jumpseat Preview</h5>
                    <hr>
                    <div class="row">
                        <div class="col-md-6">
                            <strong>Distance:</strong> <span id="distanceDisplay">0</span> NM
                        </div>
                        <div class="col-md-6">
                            <strong>Cost:</strong> <span id="costDisplay">$0.00</span>
                        </div>
                    </div>
                    <div id="sameAirportWarning" class="alert alert-warning mt-2" style="display: none;">
                        <i class="fas fa-exclamation-triangle"></i> You are already at this airport!
                    </div>
                    <div id="insufficientFundsWarning" class="alert alert-danger mt-2" style="display: none;">
                        <i class="fas fa-exclamation-circle"></i> Insufficient funds for immediate jumpseat.
                    </div>
                </div>
            </div>
            
            <div class="row">
                <div class="col-6">
                    <label>Reason</label>
                    <div class="form-group">
                        <input class="form-control" name="reason" placeholder="Optional reason..."/>
                    </div>
                </div>
            </div>
            
            <button class="btn btn-primary">Submit</button>
            <a href="{{ route('vmsopenops.jumpseat.index') }}" class="btn btn-secondary">Cancel</a>
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

    $("select.airport_search").select2({
        dropdownParent: $('body'),
        ajax: {
            url: '{{ Config::get("app.url") }}/api/airports/search',
            data: function (params) {
                const hubs_only = $(this).hasClass('hubs_only') ? 1 : 0;
                return {
                    search: params.term,
                    hubs: hubs_only,
                    page: params.page || 1,
                    orderBy: 'id',
                    sortedBy: 'asc'
                }
            },
            processResults: function (data) {
                if (!data.data) { return { results: [] } }
                const results = data.data.map(apt => ({
                    id: apt.id,
                    text: apt.description,
                }));
                const pagination = {
                    more: data.meta && data.meta.next_page !== null,
                };
                return { results, pagination };
            },
            cache: true,
            dataType: 'json',
            delay: 250,
            minimumInputLength: 2,
        },
        width: '100%',
        dropdownAutoWidth: true,
        placeholder: 'Type to search (min. 2 characters)'
    }).on('change', function() {
        const airportId = $(this).val();
        if (airportId) {
            previewJumpseat(airportId);
        } else {
            $('#previewPanel').hide();
        }
    });

    function previewJumpseat(airportId) {
        $.ajax({
            url: '{{ route("api.vmsopenops.api.jumpseat.preview") }}',
            method: 'POST',
            data: {
                to_airport_id: airportId,
                _token: '{{ csrf_token() }}'
            },
            success: function(response) {
                if (response.success) {
                    currentPreviewData = response.data;
                    $('#distanceDisplay').text(response.data.distance.value.toFixed(2));
                    $('#costDisplay').text(response.data.cost.formatted);
                    $('#previewPanel').show();
                    
                    if (response.data.is_same_airport) {
                        $('#sameAirportWarning').show();
                        $('#insufficientFundsWarning').hide();
                        $('button[type="submit"]').prop('disabled', true);
                    } else {
                        $('#sameAirportWarning').hide();
                        $('button[type="submit"]').prop('disabled', false);
                        
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
            $('#immediateCostLabel').text(currentPreviewData.cost.formatted);
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
    
    $('form').on('submit', function(e) {
        const airportId = $('.airport_search').val();
        if (!airportId) {
            e.preventDefault();
            alert('Please select a destination airport.');
            return false;
        }
        
        @if(setting('vms_open_ops_require_reason', true))
        const reason = $('input[name="reason"]').val().trim();
        if (!reason) {
            e.preventDefault();
            alert('Please provide a reason for your jumpseat request.');
            return false;
        }
        @endif
        
        return true;
    });

});
</script>
@endsection