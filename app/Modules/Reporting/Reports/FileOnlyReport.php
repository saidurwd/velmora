<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Reports;

/** A report too big to read on screen (one row per customer over a long period): the page shows its filters and the "ask for the file" button, never rows. The background file has every row. */
interface FileOnlyReport {}
