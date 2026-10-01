<?php

use App\Contracts\Migration;
use App\Models\Setting;
use Illuminate\Support\Facades\DB;

/**
 * Completa los settings de VmsOpenOps y limpia una fila huerfana.
 *
 * La migracion 000002 sembraba 7 de las 11 claves que usa el modulo: el minimo
 * del jumpseat y los tres minimos del ferry segun MTOW solo existian como valor
 * por defecto en codigo, asi que no aparecian en Admin > Settings ni se podian
 * ajustar desde el panel.
 *
 * Ademas se elimina una fila duplicada con `id` vacio para
 * vms_open_ops.jumpseat.enabled: nunca se encuentra por id, pero si aparecia en
 * el listado de Admin > Settings con un campo de formulario sin nombre.
 */
class AddMissingOperationsSettings extends Migration
{
    public function up()
    {
        $this->createOrUpdateSetting([
            'id' => 'vms_open_ops_jumpseat_min_cost',
            'key' => 'vms_open_ops.jumpseat.min_cost',
            'name' => 'Jumpseat Minimum Cost',
            'value' => '5000',
            'default' => '5000',
            'group' => 'VmsOpenOps',
            'type' => 'int',
            'description' => 'Minimum cost for a jumpseat (in cents)',
            'order' => 9102,
        ]);

        $this->createOrUpdateSetting([
            'id' => 'vms_open_ops_ferry_min_cost_light',
            'key' => 'vms_open_ops.ferry.min_cost_light',
            'name' => 'Ferry Minimum Cost (Light)',
            'value' => '20000',
            'default' => '20000',
            'group' => 'VmsOpenOps',
            'type' => 'int',
            'description' => 'Minimum ferry cost for aircraft up to 7000 kg MTOW (in cents)',
            'order' => 9203,
        ]);

        $this->createOrUpdateSetting([
            'id' => 'vms_open_ops_ferry_min_cost_medium',
            'key' => 'vms_open_ops.ferry.min_cost_medium',
            'name' => 'Ferry Minimum Cost (Medium)',
            'value' => '50000',
            'default' => '50000',
            'group' => 'VmsOpenOps',
            'type' => 'int',
            'description' => 'Minimum ferry cost for aircraft up to 136000 kg MTOW (in cents)',
            'order' => 9204,
        ]);

        $this->createOrUpdateSetting([
            'id' => 'vms_open_ops_ferry_min_cost_heavy',
            'key' => 'vms_open_ops.ferry.min_cost_heavy',
            'name' => 'Ferry Minimum Cost (Heavy)',
            'value' => '100000',
            'default' => '100000',
            'group' => 'VmsOpenOps',
            'type' => 'int',
            'description' => 'Minimum ferry cost for aircraft above 136000 kg MTOW (in cents)',
            'order' => 9205,
        ]);

        // Fila huerfana sin id que duplicaba vms_open_ops.jumpseat.enabled
        DB::table('settings')
            ->where('id', '')
            ->where('key', 'like', 'vms_open_ops%')
            ->delete();
    }

    /**
     * Helper para crear o actualizar un setting por su id
     */
    private function createOrUpdateSetting(array $data)
    {
        $existing = Setting::where('id', $data['id'])->first();

        if ($existing) {
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

            return;
        }

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

    public function down()
    {
        $settings = [
            'vms_open_ops_jumpseat_min_cost',
            'vms_open_ops_ferry_min_cost_light',
            'vms_open_ops_ferry_min_cost_medium',
            'vms_open_ops_ferry_min_cost_heavy',
        ];

        foreach ($settings as $settingId) {
            Setting::where('id', $settingId)->delete();
        }
    }
}
