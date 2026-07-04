<?php

namespace App\Infrastructure\DataQuality;

enum DataQualityMetric: string
{
    case MissingSerial = 'missing_serial';
    case MissingModel = 'missing_model';
    case MissingWarranty = 'missing_warranty';
    case MissingActivation = 'missing_activation';
    case MissingCustomerContact = 'missing_customer_contact';
    case DuplicateSerial = 'duplicate_serial';
    case UnverifiedCompletedCase = 'unverified_completed_case';
    case DuplicateServiceReference = 'duplicate_service_reference';
    case ManualInquiryRecord = 'manual_inquiry_record';
}
