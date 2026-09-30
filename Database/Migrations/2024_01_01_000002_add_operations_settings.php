<?php

use App\Contracts\Migration;
use App\Models\Setting;
use Illuminate\Support\Facades\DB;

class AddOperationsSettings extends Migration
{
    public function up()
    {
        // Jumpseat Settings
        $this->createOrUpdateSetting([
            'id' => 'vms_open_ops_jumpseat_enabled',
            'key' => 'vms_open_ops.jumpseat.enabled',
            'name' => 'Enable Jumpseat Operations',
            'value' => 'true',
            'default' => 'true',
            'group' => 'VmsOpenOps',
            'type' => 'bool',
            'description' => 'Enable jumpseat operations for pilots',
            'order' => 9100,
        ]);
        
        $this->createOrUpdateSetting([
            'id' => 'vms_open_ops_jumpseat_cost_per_nm',
            'key' => 'vms_open_ops.jumpseat.cost_per_nm',
            'name' => 'Jumpseat Cost per NM',
            'value' => '250',
            'default' => '250',
            'group' => 'VmsOpenOps',
            'type' => 'int',
            'description' => 'Cost per nautical mile for jumpseat (in cents)',
            'order' => 9101,
        ]);
        
        // Ferry Settings
        $this->createOrUpdateSetting([
            'id' => 'vms_open_ops_ferry_enabled',
            'key' => 'vms_open_ops.ferry.enabled',
            'name' => 'Enable Ferry Operations',
            'value' => 'true',
            'default' => 'true',
            'group' => 'VmsOpenOps',
            'type' => 'bool',
            'description' => 'Enable ferry operations for aircraft',
            'order' => 9200,
        ]);
        
        $this->createOrUpdateSetting([
            'id' => 'vms_open_ops_ferry_cost_per_nm',
            'key' => 'vms_open_ops.ferry.cost_per_nm',
            'name' => 'Ferry Cost per NM',
            'value' => '500',
            'default' => '500',
            'group' => 'VmsOpenOps',
            'type' => 'int',
            'description' => 'Cost per nautical mile for ferry (in cents)',
            'order' => 9201,
        ]);
        
        $this->createOrUpdateSetting([
            'id' => 'vms_open_ops_ferry_require_certification',
            'key' => 'vms_open_ops.ferry.require_certification',
            'name' => 'Require Aircraft Certification',
            'value' => 'true',
            'default' => 'true',
            'group' => 'VmsOpenOps',
            'type' => 'bool',
            'description' => 'Require pilot to be certified on subfleet for ferry',
            'order' => 9202,
        ]);
        
        // Common Settings
        $this->createOrUpdateSetting([
            'id' => 'vms_open_ops_require_reason',
            'key' => 'vms_open_ops.require_reason',
            'name' => 'Require Reason',
            'value' => 'true',
            'default' => 'true',
            'group' => 'VmsOpenOps',
            'type' => 'bool',
            'description' => 'Require reason for all operations',
            'order' => 9300,
        ]);
        
        $this->createOrUpdateSetting([
            'id' => 'vms_open_ops_max_reason_length',
            'key' => 'vms_open_ops.max_reason_length',
            'name' => 'Max Reason Length',
            'value' => '500',
            'default' => '500',
            'group' => 'VmsOpenOps',
            'type' => 'int',
            'description' => 'Maximum characters for operation reason',
            'order' => 9301,
        ]);
    }
    
    /**
     * Helper method to create or update settings without primary key issues
     */
    private function createOrUpdateSetting(array $data)
    {
        // Verificar si el setting ya existe por su ID
        $existing = Setting::where('id', $data['id'])->first();
        
        if ($existing) {
            // Actualizar existente
            $existing->update([
                'key' => $data['key'],
                'name' => $data['name'],
                'value' => $data['value'],
                'default' => $data['default'],
                'group' => $data['group'],
                'type' => $data['type'],
                'description' => $data['description'],
                'order' => $data['order'],
            ]);
        } else {
            // Crear nuevo
            $setting = new Setting();
            $setting->id = $data['id'];
            $setting->key = $data['key'];
            $setting->name = $data['name'];
            $setting->value = $data['value'];
            $setting->default = $data['default'];
            $setting->group = $data['group'];
            $setting->type = $data['type'];
            $setting->description = $data['description'];
            $setting->order = $data['order'];
            $setting->save();
        }
    }

    public function down()
    {
        $settings = [
            'vms_open_ops_jumpseat_enabled',
            'vms_open_ops_jumpseat_cost_per_nm',
            'vms_open_ops_ferry_enabled',
            'vms_open_ops_ferry_cost_per_nm',
            'vms_open_ops_ferry_require_certification',
            'vms_open_ops_require_reason',
            'vms_open_ops_max_reason_length'
        ];
        
        // Eliminar los settings uno por uno
        foreach ($settings as $settingId) {
            Setting::where('id', $settingId)->delete();
        }
    }
}