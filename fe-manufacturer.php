<!-- Select2 Styles -->
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/select2/4.0.13/css/select2.min.css">
<link rel="stylesheet" href="ingestion.css">
<h1>Manufacturer Ingestion</h1>
<?php
define('WP_USE_THEMES', false);
require_once('../wp-config.php');
?>

<div class="container">
    <div class="form-group">
        <label for="csvSelect">Select a CSV file:</label>
        <select id="csvSelect">
            <option value="">Select a CSV file</option>
            <?php
            $csvDir = __DIR__ . '/csv/manufacturer/'; // Define the CSV directory
            if (is_dir($csvDir)) {
                $csvFiles = array_filter(scandir($csvDir), function ($file) use ($csvDir) {
                    return pathinfo($file, PATHINFO_EXTENSION) === 'csv' && is_file($csvDir . $file);
                });

                foreach ($csvFiles as $file): ?>
                    <option value="<?php echo htmlspecialchars($file); ?>">
                        <?php echo htmlspecialchars($file); ?>
                    </option>
            <?php endforeach;
            }
            ?>
        </select>
    </div>

    <div class="form-group">
        <label for="mainCategory">Enter Main Category:</label>
        <input type="text" id="mainCategory" placeholder="Type main category">
    </div>

    <div class="form-group">
        <label for="subCategory">Enter Sub Category:</label>
        <input type="text" id="subCategory" placeholder="Type sub category">
    </div>

    <div style="margin: 10px 0;">
        <p id="lineLimit">
        <p id="processStatus">
        <p id="processTime">
    </div>

    <button id="startProcessing">Start Processing</button>
    <button id="stopProcessing" disabled>Stop Processing</button>
</div>

<div id="resultsContainer">
    <table>
        <thead>
            <tr>
                <th>Worker</th>
                <th>Batch</th>
                <th>Start Time</th>
                <th>End Time</th>
                <th>Processing Time (s)</th>
                <th>Fetched</th>
                <th>Duplicates</th>
                <th>Nulls</th>
                <th>Processed</th>
                <th>Remaining</th>
                <th>Message</th>
                <!-- <th>Total Time (s)</th> -->
            </tr>
        </thead>
        <tbody id="resultTableBody"></tbody>
    </table>
</div>


<script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.6.0/jquery.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/select2/4.0.13/js/select2.min.js"></script>
<script>
    jQuery(document).ready(function($) {
        $('#csvSelect').select2({
            placeholder: "Select a CSV file",
            allowClear: true
        });
    });

    document.addEventListener("DOMContentLoaded", () => {
        let functionStartTime,
            offset = 0,
            activeWorkers = 0,
            processedRows = 0,
            isProcessing = false;
        const
            batchSize = 500, // update here
            maxWorkers = 2;
        let remainingRecords = Infinity; // Track remaining count
        let totalRecords = 0;
        let summaryData = {
            totalFetched: 0,
            totalDuplicates: 0,
            totalNulls: 0,
            totalProcessed: 0
        };

        const tableBody = document.getElementById("resultTableBody");
        const startBtn = document.getElementById("startProcessing");
        const stopBtn = document.getElementById("stopProcessing");
        const resultsContainer = document.getElementById("resultsContainer");
        const lineLimit = document.getElementById("lineLimit");
        const processStatus = document.getElementById("processStatus");
        const processTime = document.getElementById("processTime");
        // Get form field values
        const csvSelect = document.getElementById("csvSelect");
        const mainCategory = document.getElementById("mainCategory");
        const subCategory = document.getElementById("subCategory");

        lineLimit.innerHTML = `Limit Per Batch: ${batchSize} rows`;

        function updateProcessedStatus() {
            const completedPercent = (processedRows / totalRecords) * 100;
            processStatus.innerHTML = `Processed: ${formatNumber(processedRows)} / ${formatNumber(totalRecords)} rows. Completed: ${completedPercent.toFixed(2)}%`;

            // update processed time
            let endTime = Date.now();
            let totalTimeInSeconds = Math.floor((endTime - functionStartTime) / 1000);

            let hours = Math.floor(totalTimeInSeconds / 3600);
            let minutes = Math.floor((totalTimeInSeconds % 3600) / 60);
            let seconds = totalTimeInSeconds % 60;

            processTime.innerHTML = `Total Time: ${hours}h ${minutes}m ${seconds}s`;

        }

        function showError(inputElement, message) {
            const errorMsg = document.createElement("p");
            errorMsg.className = "error-message";
            errorMsg.style.color = "red";
            errorMsg.style.fontSize = "12px";
            errorMsg.style.marginTop = "5px";
            errorMsg.innerText = message;

            inputElement.parentNode.appendChild(errorMsg);
        }

        // Modified updateSummaryRow to append at the bottom
        const updateSummaryRow = () => {
            let summaryRow = document.querySelector('tr[data-summary="true"]');
            if (!summaryRow) {
                summaryRow = document.createElement("tr");
                summaryRow.setAttribute("data-summary", "true");
                summaryRow.style.backgroundColor = "#e6f3ff";
                summaryRow.style.fontWeight = "bold";
            }

            let totalRecords = summaryData.totalFetched +
                summaryData.totalDuplicates +
                summaryData.totalNulls +
                summaryData.totalProcessed;

            summaryRow.innerHTML = `
                <td colspan="5">TOTALS</td>
                <td>${formatNumber(summaryData.totalFetched)}</td>
                <td>${formatNumber(summaryData.totalDuplicates)}</td>
                <td>${formatNumber(summaryData.totalNulls)}</td>
                <td>${formatNumber(summaryData.totalProcessed)}</td>
                <td>${formatNumber(remainingRecords)}</td>
                <td>Total Processed Records: ${formatNumber(totalRecords)} </td>
            `;

            tableBody.appendChild(summaryRow); // Always append to keep it at the bottom
        };

        const processBatch = (workerId) => {
            if (!isProcessing || activeWorkers >= maxWorkers || remainingRecords <= 0) return;

            activeWorkers++;
            let currentOffset = offset;
            offset += batchSize;

            let startTime = Date.now();
            let startTimestamp = getCurrentTimestamp();

            addTableRow(workerId, currentOffset, startTimestamp);
            updateSummaryRow();
            console.log(`Worker ${workerId} started processing batch ${currentOffset}`);

            fetch("<?= site_url() ?>/ingestion/function.php", {
                    method: "POST",
                    headers: {
                        "Content-Type": "application/x-www-form-urlencoded"
                    },
                    body: `offset=${currentOffset}&batchSize=${batchSize}&mainCategory=${mainCategory.value}&subCategory=${subCategory.value}&csvFile=${csvSelect.value}`
                })
                .then(response => response.json())
                .then(data => {
                    let endTime = Date.now();
                    let processingTime = ((endTime - startTime) / 1000).toFixed(2);
                    let totalTime = ((endTime - functionStartTime) / 1000).toFixed(2);

                    remainingRecords = data.remaining;
                    totalRecords = data.total_records;

                    // Update summary data
                    summaryData.totalFetched += parseInt(data.fetched) || 0;
                    summaryData.totalDuplicates += parseInt(data.duplicates) || 0;
                    summaryData.totalNulls += parseInt(data.nulls) || 0;
                    summaryData.totalProcessed += parseInt(data.processed) || 0;

                    let batchTotal = data.fetched + data.duplicates + data.nulls + data.processed;
                    processedRows += batchTotal;
                    console.log('processedRows', processedRows);
                    updateProcessedStatus();

                    updateTableRow(currentOffset, getCurrentTimestamp(), processingTime, totalTime, data);
                    updateSummaryRow();

                    if (remainingRecords > 0 && isProcessing) {
                        // console.log(`Worker ${workerId} continues processing...`);
                        activeWorkers--;
                        processBatch(workerId);
                    }
                })
                .catch(error => {
                    console.error(`❌ Error in worker ${workerId}: ${error}`);
                    activeWorkers--;
                })
                .finally(() => {
                    // console.log(`Worker ${workerId} finished processing. Active workers: ${activeWorkers}`);
                    if (remainingRecords <= 0 || !isProcessing) {
                        isProcessing = false;
                        startBtn.disabled = false;
                        stopBtn.disabled = true;
                    }
                });
        };

        // Add a row immediately when the job is dispatched
        const addTableRow = (worker, batch, startTime) => {
            let row = document.createElement("tr");
            row.setAttribute("data-batch", batch); // Set batch as identifier
            row.innerHTML = `
                <td>${worker}</td>
                <td>${formatNumber(batch)} - ${formatNumber(batch + batchSize)}</td>
                <td>${startTime}</td>
                <td></td>
                <td></td>
                <td></td>
                <td></td>
                <td></td>
                <td></td>
                <td></td>
                <td>⌛ Waiting for response...</td>
            `;
            tableBody.appendChild(row);
        };

        // Update row when API response is received
        const updateTableRow = (batch, endTime, processingTime, totalTime, data) => {
            let row = document.querySelector(`tr[data-batch="${batch}"]`);
            if (row) {
                row.cells[3].textContent = endTime; // End Time
                row.cells[4].textContent = formatNumber(processingTime); // Processing Time
                row.cells[5].textContent = formatNumber(data.fetched); // Fetched
                row.cells[6].textContent = formatNumber(data.duplicates); // Duplicates
                row.cells[7].textContent = formatNumber(data.nulls); // Nulls
                row.cells[8].textContent = formatNumber(data.processed); // Processed
                row.cells[9].textContent = formatNumber(data.remaining); // Remaining
                row.cells[10].textContent = data.message; // Message
                // row.cells[11].textContent = formatNumber(totalTime); // Total Time
            }
        };

        function validateField() {
            // Remove existing error messages
            document.querySelectorAll(".error-message").forEach(el => el.remove());

            let isValid = true;

            // Validate CSV Select
            if (!csvSelect.value) {
                showError(csvSelect, "Please select a CSV file.");
                isValid = false;
            }

            // Validate Main Category
            if (!mainCategory.value.trim()) {
                showError(mainCategory, "Main Category is required.");
                isValid = false;
            }

            // Validate Sub Category
            if (!subCategory.value.trim()) {
                showError(subCategory, "Sub Category is required.");
                isValid = false;
            }

            // If all fields are valid, proceed with the processing
            if (isValid) {
                return true;
            }
        }

        startBtn.addEventListener("click", () => {
            if (!validateField()) return;

            resultsContainer.style.display = "block"; // Show the table
            functionStartTime = Date.now();
            isProcessing = true;
            offset = 0;
            activeWorkers = 0;
            remainingRecords = Infinity;
            totalRecords = 0;
            summaryData = {
                totalFetched: 0,
                totalDuplicates: 0,
                totalNulls: 0,
                totalProcessed: 0
            };
            tableBody.innerHTML = "";

            startBtn.disabled = true;
            stopBtn.disabled = false;

            for (let i = 0; i < maxWorkers; i++) {
                // console.log(`Starting worker ${i + 1}`);
                processBatch(i + 1);
            }
        });

        stopBtn.addEventListener("click", () => {
            isProcessing = false;
            startBtn.disabled = false;
            stopBtn.disabled = true;
            console.log("Processing stopped");
        });

        function getCurrentTimestamp() {
            return new Date().toLocaleString();
        }

        function formatNumber(value) {
            if (!value) return 0;
            return Number(value).toLocaleString("en-US");
        }
    });
</script>