<?php
define('WP_USE_THEMES', false);
require_once('../wp-config.php');
?>

<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/select2/4.0.13/css/select2.min.css">
<link rel="stylesheet" href="ingestion-v1.css">

<div class="container">
    <h1>Manufacturer CSV Import</h1>

    <div class="form-group">
        <label>CSV File:</label>
        <select id="csvSelect">
            <option value="">Select CSV</option>
            <?php
            $csvFiles = array_filter(
                scandir(__DIR__ . '/csv/manufacturer/'),
                fn($f) => pathinfo($f, PATHINFO_EXTENSION) === 'csv'
            );
            foreach ($csvFiles as $file) {
                echo "<option value='" . htmlspecialchars($file) . "'>" . htmlspecialchars($file) . "</option>";
            }
            ?>
        </select>
    </div>
    <div id="status"></div>
    <div id="error-container"></div>

    <button id="startBtn">Start Import</button>
    <button id="stopBtn" disabled>Stop</button>

    <table id="resultTable" style="display:none">
        <thead>
            <tr>
                <th>Batch</th>
                <th>Processed</th>
                <th>Fetched</th>
                <th>Duplicates</th>
                <th>Updated</th>
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
        const BATCH_SIZE = 100;
        const MAX_WORKERS = 2;

        const elements = {
            csv: document.getElementById('csvSelect'),
            source: document.getElementById('csvSource'),
            mainCat: document.getElementById('mainCategory'),
            subCat: document.getElementById('subCategory'),
            startBtn: document.getElementById('startBtn'),
            stopBtn: document.getElementById('stopBtn'),
            status: document.getElementById('status'),
            table: document.getElementById('resultTable'),
            tbody: document.querySelector('#resultTable tbody'),
            errorContainer: document.getElementById('error-container')
        };

        elements.status.innerHTML += `Limit per batch: ${BATCH_SIZE.toLocaleString()} rows<br>No. of Workers: ${MAX_WORKERS.toLocaleString()}`;

        let state;

        function resetState() {
            // Reset state variables
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

            elements.errorContainer.innerHTML = '';
            elements.errorContainer.style.display = 'none';
        }
        resetState();

        // --- Batch Processing Functions (unchanged) ---
        async function processBatch(workerId, offset) {
            // console.log(workerId, offset);
            if (!state.isProcessing) return;

            // Mark this batch as dispatched
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
                const response = await fetch('<?= site_url() ?>/ingestion/be-manufacturer.php', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/x-www-form-urlencoded'
                        },
                        body: new URLSearchParams({
                            csvFile: elements.csv.value,
                            // csvSource: elements.source.value,
                            // mainCategory: elements.mainCat.value, // now using select2 value
                            // subCategory: elements.subCat.value, // now using select2 value
                            offset: offset,
                            batchSize: BATCH_SIZE
                        })
                    })
                    .then(response => response.text()) // Get raw response as text
                    .then(parseJsonResponse); // Parse JSON safely

                if (!response || !response.data) {
                    throw new Error("Invalid response format");
                }

                // Extract `data` safely
                const {
                    data
                } = response;

                // Set total on the first batch
                if (offset === 0) {
                    state.total = parseInt(data.total_records) || 0;
                }

                // Update processed count based on all stats
                const currentBatchTotal = (parseInt(data.processed) || 0) +
                    (parseInt(data.duplicates) || 0) +
                    (parseInt(data.nulls) || 0);

                state.processed += currentBatchTotal;

                // If no records remaining, update processed count to total
                if (data.remaining === 0) {
                    state.processed = state.total;
                }

                updateStatus();
                addTableRow(offset, data);

                if (!response.success) {
                    elements.errorContainer.innerHTML = '<h1 style="margin-top: 0;">Error</h1>'
                    elements.errorContainer.innerHTML += data.message;
                    elements.errorContainer.style.display = 'block';

                    // update buttons
                    state.isProcessing = false;
                    elements.startBtn.disabled = false;
                    elements.stopBtn.disabled = true;

                    updateStatus(true);
                    return;
                }

                // If more records remain and processing hasn’t been stopped, queue the next batch
                if (data.remaining > 0 && state.isProcessing) {
                    processBatch(workerId, state.dispatchedTotal);
                    state.dispatchedTotal += BATCH_SIZE;
                } else {
                    checkCompletion();
                }
            } catch (error) {
                console.log(error);
                console.error(`Worker ${workerId} error:`, error);
                addTableRow(offset, {
                    message: '❌ Error: ' + error.message
                });
            }
        }

        function updateStatus(forceEnd = false) {
            const progress = state.total > 0 ? ((state.processed / state.total) * 100).toFixed(1) : 0;
            const elapsed = Math.floor((Date.now() - state.startTime) / 1000);

            let statusText = `
            Limit per batch: ${BATCH_SIZE.toLocaleString()} rows<br>
            Worker: ${MAX_WORKERS.toLocaleString()}<br>
            Progress: ${progress}% (${state.processed.toLocaleString()} / ${state.total.toLocaleString()})<br>
            Time: ${Math.floor(elapsed / 60)}m ${elapsed % 60}s
        `;

            if (forceEnd) {
                statusText += '';
            } else if (state.processed >= state.total && state.total > 0) {
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
                <td>${(data.updated || 0).toLocaleString()}</td>
                <td>${(data.nulls || 0).toLocaleString()}</td>
                <td>${data.remaining ? data.remaining.toLocaleString() : '...'}</td>
                <td>${data.process_time ? data.process_time + 's' : '-'}</td>
                <td>${data.message}</td>
            `
            });

            // Sort rows and update table display
            updateTable();
        }

        function updateTable() {
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

        function parseJsonResponse(responseText) {
            try {
                // Extract JSON substring
                const jsonStart = responseText.indexOf('{');
                const jsonEnd = responseText.lastIndexOf('}') + 1;

                if (jsonStart === -1 || jsonEnd === 0) {
                    throw new Error("No valid JSON found in response");
                }

                const jsonString = responseText.substring(jsonStart, jsonEnd);

                // Parse JSON
                return JSON.parse(jsonString);
            } catch (error) {
                console.error("Error parsing JSON:", error.message);
                return null; // Return null or an empty object depending on your use case
            }
        }

        // --- Start / Stop Button Event Handlers ---
        elements.startBtn.addEventListener('click', () => {
            if (!elements.csv.value) {
                alert('Please fill all fields');
                return;
            }
            resetState();
            elements.tbody.innerHTML = '';
            elements.table.style.display = 'table';
            elements.startBtn.disabled = true;
            elements.stopBtn.disabled = false;

            // Start the workers for batch processing
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
</body>

</html>