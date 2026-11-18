<?php

/*
 * Case / carton label layout (3 x 2 inch thermal label). This is the ONE place the
 * layout lives: change sizes and positions here when the label changes.
 * Matches Andy's sample (Nov 17, 2026) and the preview he approved.
 * Units are PDF points (72 pt = 1 inch). y is measured from the BOTTOM of the label.
 */
return [
    'template' => 'case-3x2-v1',
    'name' => 'Case / carton label 3 × 2 in',
    'page' => ['width' => 216, 'height' => 144],   // 3 in x 2 in

    'text' => [
        'font' => 'Helvetica-Bold',     // bold black sans-serif, left-aligned
        'left' => 9,                    // left margin
        'max_width' => 198,             // longest line must fit; the font shrinks to fit
        'size_with_barcode' => 10.5,
        'size_without_barcode' => 12,
        'min_size' => 6,                // never smaller (6 pt)
        'leading' => 1.135,             // line spacing = font size x this
        'top' => 14,                    // with barcode: first baseline = top + font size x first_line_drop (from the top edge)
        'first_line_drop' => 0.78,
    ],

    'barcode' => [
        'type' => 'code128',
        'x' => 9, 'y' => 15, 'width' => 198, 'height' => 26,
        'text_font' => 'Helvetica', 'text_size' => 8, 'text_y' => 6,   // human-readable number, centred
        'max_length' => 48,
        'min_module' => 0.71,   // narrowest bar = 2 dots at 203 dpi; the editor never makes the barcode narrower than this
    ],

    // Default wording of each line (all editable before printing).
    'defaults' => [
        'batch_label' => 'Batch #',
        'count_label' => 'Count',
        'case_label' => 'Case',
        'per_case_label' => 'Count per case box',
        // Added to the "Case" line when the total does not divide evenly, e.g. "32 Case (1 partial; 5 count)".
        'partial_suffix' => ' (1 partial; {remainder} count)',
    ],

    // Case box label (the small boxes inside a master box): product name, batch number and
    // the count in that box, plus the barcode when the brand requires one. Same 3 x 2 in stock.
    'casebox' => [
        'template' => 'casebox-3x2-v1',
        'name' => 'Case box label 3 × 2 in',
        'size_with_barcode' => 12,
        'size_without_barcode' => 14,
    ],

    // On-label editor (per print job; optional per-product template).
    'editor' => ['max_size' => 24],

    'max_copies' => 1000,
];
