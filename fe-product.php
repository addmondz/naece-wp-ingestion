<?php
define('WP_USE_THEMES', false);
require_once('../wp-config.php');
?>

<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/select2/4.0.13/css/select2.min.css">
<link rel="stylesheet" href="ingestion.css">

<div class="container">
    <h1>Product CSV Import</h1>

    <div class="form-group">
        <label>CSV File:</label>
        <select id="csvSelect">
            <option value="">Select CSV</option>
            <?php
            $csvFiles = array_filter(
                scandir(__DIR__ . '/csv/product/'),
                fn($f) => pathinfo($f, PATHINFO_EXTENSION) === 'csv'
            );
            foreach ($csvFiles as $file) {
                echo "<option value='" . htmlspecialchars($file) . "'>" . htmlspecialchars($file) . "</option>";
            }
            ?>
        </select>
    </div>

    <div class="form-group">
        <label>Main Category:</label>
        <input type="text" id="mainCategory">
    </div>

    <div class="form-group">
        <label>Sub Category:</label>
        <input type="text" id="subCategory">
    </div>

    <div id="status"></div>

    <button id="startBtn">Start Import</button>
    <button id="stopBtn" disabled>Stop</button>

    <table id="resultTable" style="display:none">
        <thead>
            <tr>
                <th>Batch</th>
                <th>Processed</th>
                <th>Fetched</th>
                <th>Duplicates</th>
                <th>Nulls</th>
                <th>Remaining</th>
                <th>Time (s)</th>
                <th>Status</th>
            </tr>
        </thead>
        <tbody></tbody>
    </table>
</div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.6.0/jquery.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/select2/4.0.13/js/select2.min.js"></script>

<script>
    document.addEventListener('DOMContentLoaded', () => {
        const BATCH_SIZE = 500;
        const MAX_WORKERS = 2;

        const elements = {
            csv: document.getElementById('csvSelect'),
            mainCat: document.getElementById('mainCategory'),
            subCat: document.getElementById('subCategory'),
            startBtn: document.getElementById('startBtn'),
            stopBtn: document.getElementById('stopBtn'),
            status: document.getElementById('status'),
            table: document.getElementById('resultTable'),
            tbody: document.querySelector('#resultTable tbody')
        };

        elements.status.innerHTML += `Limit per batch: ${BATCH_SIZE.toLocaleString()} rows<br>Worker: ${MAX_WORKERS.toLocaleString()}`;

        let state;

        function resetState() {
            // Reset state
            state = {
                isProcessing: true,
                offset: 0,
                total: 0,
                processed: 0,
                startTime: Date.now(),
                dispatchedTotal: 0,
                rows: [],
                dispatchedBatches: new Set()
            };
        }
        resetState();

        // Initialize Select2
        jQuery('#csvSelect').select2({
            placeholder: 'Select CSV file'
        });

        async function processBatch(workerId, offset) {
            console.log(workerId, offset);
            if (!state.isProcessing) return;

            // Add to dispatched batches immediately
            state.dispatchedBatches.add(offset);
            addTableRow(offset, {
                message: '⌛ Processing...',
                processed: 0,
                fetched: 0,
                duplicates: 0,
                nulls: 0,
                process_time: null,
                remaining: '...'
            });

            try {
                const response = await fetch('<?= site_url() ?>/ingestion/function.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded'
                    },
                    body: new URLSearchParams({
                        csvFile: elements.csv.value,
                        mainCategory: elements.mainCat.value,
                        subCategory: elements.subCat.value,
                        offset: offset,
                        batchSize: BATCH_SIZE
                    })
                });

                const {
                    data
                } = await response.json();

                // Update total and processed counts
                if (offset === 0) {
                    state.total = parseInt(data.total_records) || 0;
                }

                // Update processed count based on all stats
                const currentBatchTotal = (parseInt(data.processed) || 0) +
                    (parseInt(data.duplicates) || 0) +
                    (parseInt(data.nulls) || 0);

                state.processed += currentBatchTotal;

                // Check if this is the last batch
                if (data.remaining === 0) {
                    state.processed = state.total; // Ensure we show 100%
                }

                updateStatus();
                addTableRow(offset, data);

                // Continue if more records exist
                // console.log(data.remaining);
                if (data.remaining > 0 && state.isProcessing) {
                    processBatch(workerId, state.dispatchedTotal);
                    state.dispatchedTotal += BATCH_SIZE;
                } else {
                    checkCompletion();
                }
            } catch (error) {
                console.error(`Worker ${workerId} error:`, error);
                addTableRow(offset, {
                    message: '❌ Error: ' + error.message
                });
            }
        }

        function updateStatus() {
            const progress = state.total > 0 ? ((state.processed / state.total) * 100).toFixed(1) : 0;
            const elapsed = Math.floor((Date.now() - state.startTime) / 1000);

            let statusText = `
            Limit per batch: ${BATCH_SIZE.toLocaleString()} rows<br>
            Worker: ${MAX_WORKERS.toLocaleString()}<br>
            Progress: ${progress}% (${state.processed.toLocaleString()} / ${state.total.toLocaleString()})<br>
            Time: ${Math.floor(elapsed / 60)}m ${elapsed % 60}s
        `;

            if (state.processed >= state.total && state.total > 0) {
                statusText += '<br>✅ Import Completed!';
            } else {
                statusText += '<br>⌛ Processing...';
            }

            elements.status.innerHTML = statusText;
        }

        function addTableRow(offset, data) {
            state.rows = state.rows.filter(row => row.offset !== offset);
            state.rows.push({
                offset,
                data,
                html: `
                <td>${offset.toLocaleString()} - ${(offset + BATCH_SIZE).toLocaleString()}</td>
                <td>${(data.processed || 0).toLocaleString()}</td>
                <td>${(data.fetched || 0).toLocaleString()}</td>
                <td>${(data.duplicates || 0).toLocaleString()}</td>
                <td>${(data.nulls || 0).toLocaleString()}</td>
                <td>${data.remaining ? data.remaining.toLocaleString() : '...'}</td>
                <td>${data.process_time + 's' ?? '-'}</td>
                <td>${data.message}</td>
            `
            });

            // Sort and update table
            updateTable();
        }

        function updateTable() {
            // Sort rows by offset
            state.rows.sort((a, b) => a.offset - b.offset);
            elements.tbody.innerHTML = state.rows
                .map(row => `<tr>${row.html}</tr>`)
                .join('');
        }

        function checkCompletion() {
            if (state.processed >= state.total && state.total > 0) {
                state.isProcessing = false;
                elements.startBtn.disabled = false;
                elements.stopBtn.disabled = true;
                updateStatus();
            }
        }

        elements.startBtn.addEventListener('click', () => {
            if (!elements.csv.value || !elements.mainCat.value || !elements.subCat.value) {
                alert('Please fill all fields');
                return;
            }

            // Reset state
            resetState();

            // Update UI
            elements.tbody.innerHTML = '';
            elements.table.style.display = 'table';
            elements.startBtn.disabled = true;
            elements.stopBtn.disabled = false;

            // Start workers
            for (let i = 0; i < MAX_WORKERS; i++) {
                processBatch(i + 1, state.dispatchedTotal);
                state.dispatchedTotal += BATCH_SIZE;
            }
        });

        elements.stopBtn.addEventListener('click', () => {
            state.isProcessing = false;
            elements.startBtn.disabled = false;
            elements.stopBtn.disabled = true;
        });
    });
</script>