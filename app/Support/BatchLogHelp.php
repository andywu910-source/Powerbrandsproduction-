<?php

namespace App\Support;

/** Definitions shown in the Batch Log column info tips (tap / hover / keyboard). Written from the code as built. */
class BatchLogHelp
{
    public const COLUMNS = [
        'batch_number' => 'The batch number typed by the person who started the batch on Start a new batch (or the cleaned Batch ID for batches imported from the Google Sheet). It never changes once the batch exists and is the number printed on labels.',
        'product' => 'The product the batch was started for (picked with Brand › Product line › Flavor on Start a new batch). Imported batches: the product matched from the sheet row.',
        'brand' => 'The brand of that product (Setup → Products).',
        'lab_samples' => 'Units the lab takes for each test, from the units planned and the product\'s serving type (single-serve or multi-serving) using the OCM sampling table (OCM Cannabis Sampling QS Standard rev. 1/14/26, Table 1). "Over 150,000" = more units than the table covers.',
        'retention' => 'Units to keep as retention samples: 2 × the lab sample count for complete testing (OCM Final Cannabis Product Batch item C). Empty when the units planned are not known.',
        'barcode' => 'The barcode number printed on case labels (usually the Metrc package tag). It is not made from the batch number. Imported batches take it from the sheet\'s Barcode column; admins and supervisors can add or change it on the batch page (every change is in the audit log). "Missing" = the brand\'s labels need a barcode and this batch has none yet.',
        'units' => 'Units planned: the number of units typed on Start a new batch. For batches imported from the Google Sheet it is the sheet\'s QTY (left empty when the QTY is not a number).',
        'status' => 'Incoming: a job that is planned but not started yet (it has its product and batch number; planned units and date are optional). In production: the batch has been started and is being made. In lab: samples are at the lab or the batch is waiting for lab results. Packaging/COA labeling: the batch is being packaged and labeled, or its COA is being added. Finished: the batch is done. An Incoming job moves to In production only with "Start this job", which runs the normal start checks (approved recipe, product and brand set up, number of units). After that, anyone who can edit batch records (not read-only accounts) can set In production, In lab, Packaging/COA labeling or Finished in any order with the Status menu; a started batch can\'t go back to Incoming. Every change is written to the audit log with the old and new value and who made it. There is no QC step: the status is only a tracking mark and does not sign, lock or void the production record. Labels can be printed at any status.',
    ];
}
