<?php

return [
    // Switch only after F01 staging and reader parity have been verified.
    'writer_mode' => env('M1_WO_WRITER_MODE', 'expand'),
];
