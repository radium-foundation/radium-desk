<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ReferenceSequence extends Model
{
    public const SC = 'sc';

    /** Independent refund operational series (REF-67315+). Not statutory INV-* numbering. */
    public const REFUND_OPERATIONAL = 'refund_operational';

    /** Independent service order operational series (SVC-671+). Not database-id derived. */
    public const SERVICE_ORDER_OPERATIONAL = 'service_order_operational';

    /** Independent Product POS operational series (POS-6720+). Not database-id derived. */
    public const PRODUCT_POS_OPERATIONAL = 'product_pos_operational';

    protected $primaryKey = 'name';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'name',
        'current_value',
    ];

    protected function casts(): array
    {
        return [
            'current_value' => 'integer',
        ];
    }
}
