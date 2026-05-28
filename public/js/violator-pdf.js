(function() {
    function loadEvidenceImage(relativePath) {
        return new Promise(function(resolve) {
            if (!relativePath) {
                resolve(null);
                return;
            }

            var img = new Image();
            img.onload = function() {
                try {
                    var canvas = document.createElement('canvas');
                    canvas.width = img.naturalWidth || img.width;
                    canvas.height = img.naturalHeight || img.height;
                    var ctx = canvas.getContext('2d');
                    ctx.drawImage(img, 0, 0);
                    var dataUrl = canvas.toDataURL('image/jpeg', 0.88);
                    resolve({
                        dataUrl: dataUrl,
                        width: canvas.width,
                        height: canvas.height
                    });
                } catch (e) {
                    resolve(null);
                }
            };
            img.onerror = function() {
                resolve(null);
            };
            img.src = new URL('../uploads/' + relativePath, window.location.href).href;
        });
    }

    function loadReportLogo() {
        return new Promise(function(resolve) {
            var sourceCandidates = [];
            var inlineLogo = document.querySelector('.report-logo-wrap img');
            if (inlineLogo && inlineLogo.getAttribute('src')) {
                sourceCandidates.push(inlineLogo.getAttribute('src'));
            }
            sourceCandidates.push('../assets/images/traffic-logo.png');
            sourceCandidates.push('/assets/images/traffic-logo.png');
            sourceCandidates.push('assets/images/traffic-logo.png');
            sourceCandidates.push('../uploads/Pototan%20Logo%20no%20bg.png');
            sourceCandidates.push('/uploads/Pototan%20Logo%20no%20bg.png');
            sourceCandidates.push('uploads/Pototan%20Logo%20no%20bg.png');
            sourceCandidates.push('../assets/images/pototan-logo-no-bg.png');
            sourceCandidates.push('/assets/images/pototan-logo-no-bg.png');
            sourceCandidates.push('assets/images/pototan-logo-no-bg.png');

            var attemptLoad = function(index) {
                if (index >= sourceCandidates.length) {
                    resolve(null);
                    return;
                }
                var candidate = sourceCandidates[index];
                if (!candidate) {
                    attemptLoad(index + 1);
                    return;
                }
                var logo = new Image();
                logo.onload = function() {
                    try {
                        var sourceW = logo.naturalWidth || logo.width;
                        var sourceH = logo.naturalHeight || logo.height;
                        if (!sourceW || !sourceH) {
                            attemptLoad(index + 1);
                            return;
                        }
                        var canvas = document.createElement('canvas');
                        canvas.width = sourceW;
                        canvas.height = sourceH;
                        var ctx = canvas.getContext('2d');
                        ctx.clearRect(0, 0, sourceW, sourceH);
                        ctx.drawImage(logo, 0, 0, sourceW, sourceH);
                        resolve({
                            dataUrl: canvas.toDataURL('image/png'),
                            width: sourceW,
                            height: sourceH
                        });
                    } catch (e) {
                        attemptLoad(index + 1);
                    }
                };
                logo.onerror = function() {
                    attemptLoad(index + 1);
                };
                logo.src = new URL(candidate, window.location.href).href;
            };
            attemptLoad(0);
        });
    }

    async function createViolatorPdf(payload) {
        var jsPDF = window.jspdf.jsPDF;
        var doc = new jsPDF({ unit: 'mm', format: 'a4' });
        var pageWidth = doc.internal.pageSize.getWidth();
        var pageHeight = doc.internal.pageSize.getHeight();
        var margin = 11;
        var contentWidth = pageWidth - (margin * 2);
        var y = margin;
        var reportLogo = await loadReportLogo();

        var ensureSpace = function(requiredHeight) {
            if (y + requiredHeight > pageHeight - margin) {
                doc.addPage();
                y = margin;
            }
        };

        var writeText = function(text, size, gap, weight, color) {
            var fontSize = typeof size === 'number' ? size : 10;
            var lineGap = typeof gap === 'number' ? gap : 6;
            var fontWeight = weight || 'normal';
            var fontColor = color || [31, 41, 55];
            doc.setFontSize(fontSize);
            doc.setFont('helvetica', fontWeight);
            doc.setTextColor(fontColor[0], fontColor[1], fontColor[2]);
            var lines = doc.splitTextToSize(String(text), contentWidth - 6);
            var lineHeight = fontSize >= 11 ? 5.2 : 4.8;
            ensureSpace((lines.length * lineHeight) + lineGap);
            doc.text(lines, margin + 3, y);
            y += (lines.length * lineHeight) + lineGap;
        };

        var writeKeyValue = function(label, value) {
            var v = (value === undefined || value === null || value === '') ? 'N/A' : String(value);
            writeText(label + ': ' + v, 10.2, 5.8, 'normal', [23, 45, 88]);
        };

        // Header band
        ensureSpace(30);
        doc.setDrawColor(173, 196, 235);
        doc.setFillColor(239, 246, 255);
        doc.roundedRect(margin, y, contentWidth, 28, 2.5, 2.5, 'FD');
        doc.setFont('helvetica', 'bold');
        doc.setFontSize(14.5);
        doc.setTextColor(13, 36, 79);
        doc.text('MUNICIPAL TRAFFIC MANAGEMENT OFFICE - PNP', margin + 4, y + 8);
        doc.setFont('helvetica', 'normal');
        doc.setFontSize(10.8);
        doc.setTextColor(53, 78, 128);
        doc.text('Pototan, Iloilo | Official Violator Monitoring Document', margin + 4, y + 14);
        doc.setTextColor(79, 99, 142);
        doc.text('Generated: ' + new Date().toLocaleString(), margin + 4, y + 21);
        if (reportLogo) {
            var logoMaxW = 24;
            var logoMaxH = 24;
            var logoScale = Math.min(logoMaxW / reportLogo.width, logoMaxH / reportLogo.height);
            var logoW = reportLogo.width * logoScale;
            var logoH = reportLogo.height * logoScale;
            var logoX = margin + contentWidth - logoW - 3;
            var logoY = y + 2;
            doc.addImage(reportLogo.dataUrl, 'PNG', logoX, logoY, logoW, logoH);
        }
        y += 34;

        // Document title
        writeText('Violator Profile and Violation History Report', 15, 8, 'bold', [15, 52, 110]);

        // Profile summary box
        ensureSpace(35);
        doc.setDrawColor(222, 231, 246);
        doc.setFillColor(255, 255, 255);
        doc.roundedRect(margin, y, contentWidth, 32, 2.2, 2.2, 'FD');
        var profileTop = y + 7;
        doc.setFont('helvetica', 'bold');
        doc.setFontSize(11.6);
        doc.setTextColor(31, 56, 104);
        doc.text('Violator Information', margin + 4, profileTop);
        doc.setFont('helvetica', 'normal');
        doc.setTextColor(31, 41, 55);
        doc.setFontSize(10.4);
        doc.text('Name: ' + (payload.full_name || 'N/A'), margin + 4, profileTop + 7);
        doc.text('License Number: ' + (payload.license_number || 'N/A'), margin + 72, profileTop + 7);
        doc.text('Plate Number: ' + (payload.plate || 'N/A'), margin + 4, profileTop + 14);
        doc.text('Total Offenses: ' + (payload.total_offenses || 0), margin + 72, profileTop + 14);
        var addressText = doc.splitTextToSize('Address: ' + (payload.address || 'N/A'), contentWidth - 8);
        doc.text(addressText, margin + 4, profileTop + 21);
        y += 40;

        var violations = Array.isArray(payload.violations) ? payload.violations : [];
        for (var index = 0; index < violations.length; index++) {
            var v = violations[index];
            var evidenceRows = [];
            var evidenceList = Array.isArray(v.evidence) ? v.evidence : [];
            for (var evIdx = 0; evIdx < evidenceList.length; evIdx++) {
                if (evIdx >= 3) {
                    break;
                }
                var ev = evidenceList[evIdx];
                var loaded = await loadEvidenceImage(ev.file_path);
                if (loaded) {
                    evidenceRows.push({
                        image: loaded,
                        label: ev.evidence_label || (ev.evidence_type ? String(ev.evidence_type).replace(/_/g, ' ') : 'Evidence')
                    });
                }
            }

            var descriptionLines = doc.splitTextToSize('Description: ' + (v.incident_description || 'No narrative submitted.'), contentWidth - 8);
            var evidenceAreaHeight = evidenceRows.length > 0 ? 72 : 8;
            var blockHeight = 31 + (descriptionLines.length * 5) + evidenceAreaHeight;
            ensureSpace(blockHeight + 6);

            doc.setDrawColor(214, 225, 243);
            doc.setFillColor(250, 252, 255);
            doc.roundedRect(margin, y, contentWidth, blockHeight, 2, 2, 'FD');

            var rowY = y + 6;
            doc.setFont('helvetica', 'bold');
            doc.setFontSize(10);
            doc.setTextColor(22, 58, 126);
            doc.text('Violation Entry #' + (index + 1), margin + 4, rowY);
            doc.setDrawColor(228, 236, 249);
            doc.line(margin + 4, rowY + 1.8, margin + contentWidth - 4, rowY + 1.8);
            rowY += 7;

            doc.setFont('helvetica', 'normal');
            doc.setFontSize(10.1);
            doc.setTextColor(31, 41, 55);
            doc.text('TOP Number: ' + (v.top_number || 'N/A'), margin + 4, rowY);
            doc.text('Date and Time: ' + (v.violation_date || 'N/A'), margin + 96, rowY);
            rowY += 6.2;

            doc.text('Offense: ' + (v.violation_display || 'N/A'), margin + 4, rowY);
            doc.text('Status: ' + (v.status || 'N/A'), margin + 96, rowY);
            rowY += 6.2;

            doc.text('Location: ' + (v.location || 'N/A'), margin + 4, rowY);
            doc.text('Penalty Amount: PHP ' + (Number(v.fine_amount || 0).toFixed(2)), margin + 96, rowY);
            rowY += 6.2;

            doc.text(descriptionLines, margin + 4, rowY);
            rowY += (descriptionLines.length * 5);

            if (evidenceRows.length > 0) {
                doc.setFont('helvetica', 'bold');
                doc.setFontSize(9.8);
                doc.setTextColor(31, 56, 104);
                doc.text('Attached Evidence Images:', margin + 4, rowY + 3);

                var thumbY = rowY + 5;
                var boxW = 54;
                var maxH = 46;
                for (var thumbIndex = 0; thumbIndex < evidenceRows.length; thumbIndex++) {
                    var thumbX = margin + 4 + (thumbIndex * (boxW + 4));
                    var entry = evidenceRows[thumbIndex];
                    var format = entry.image.dataUrl.indexOf('image/png') >= 0 ? 'PNG' : 'JPEG';

                    var originalW = entry.image.width || boxW;
                    var originalH = entry.image.height || maxH;
                    var scale = Math.min(boxW / originalW, maxH / originalH);
                    var drawW = Math.max(12, originalW * scale);
                    var drawH = Math.max(12, originalH * scale);
                    var drawX = thumbX + ((boxW - drawW) / 2);
                    var drawY = thumbY + ((maxH - drawH) / 2);

                    doc.setDrawColor(214, 225, 243);
                    doc.setFillColor(255, 255, 255);
                    doc.roundedRect(thumbX, thumbY, boxW, maxH, 1.5, 1.5, 'FD');
                    doc.addImage(entry.image.dataUrl, format, drawX, drawY, drawW, drawH);
                    doc.setFont('helvetica', 'normal');
                    doc.setFontSize(9);
                    doc.setTextColor(79, 99, 142);
                    var labelLines = doc.splitTextToSize(entry.label, boxW);
                    doc.text(labelLines, thumbX, thumbY + maxH + 3);
                }
            } else {
                doc.setFont('helvetica', 'normal');
                doc.setFontSize(9.4);
                doc.setTextColor(82, 106, 149);
                doc.text('Attached Evidence: None', margin + 4, rowY + 1);
            }
            y += blockHeight + 4;
        }

        // Signature/attestation block for official hardcopy workflows
        ensureSpace(44);
        doc.setDrawColor(214, 225, 243);
        doc.setFillColor(252, 253, 255);
        doc.roundedRect(margin, y, contentWidth, 36, 2, 2, 'FD');
        doc.setFont('helvetica', 'bold');
        doc.setFontSize(10.8);
        doc.setTextColor(22, 58, 126);
        doc.text('Certification and Signatories', margin + 4, y + 6);

        var sigTop = y + 18;
        var colWidth = contentWidth / 3;
        var col1 = margin + 4;
        var col2 = margin + colWidth + 2;
        var col3 = margin + (colWidth * 2);

        doc.setDrawColor(160, 177, 206);
        doc.line(col1, sigTop, col1 + colWidth - 10, sigTop);
        doc.line(col2, sigTop, col2 + colWidth - 10, sigTop);
        doc.line(col3, sigTop, col3 + colWidth - 10, sigTop);

        doc.setFont('helvetica', 'normal');
        doc.setFontSize(9.4);
        doc.setTextColor(49, 64, 97);
        doc.text('Prepared by', col1 + 10, sigTop + 5);
        doc.text('Verified by', col2 + 12, sigTop + 5);
        doc.text('Noted by', col3 + 14, sigTop + 5);

        doc.setFontSize(8.8);
        doc.setTextColor(82, 106, 149);
        doc.text('PNP Records Officer', col1 + 2, sigTop + 10);
        doc.text('PNP Supervisor / Reviewer', col2 + 1, sigTop + 10);
        doc.text('Station Commander', col3 + 8, sigTop + 10);
        y += 40;

        ensureSpace(10);
        doc.setFont('helvetica', 'italic');
        doc.setFontSize(9);
        doc.setTextColor(82, 106, 149);
        doc.text('This document is system-generated for official PNP monitoring, reporting, and records documentation.', margin, pageHeight - 8);

        var safeName = (payload.full_name || 'report').replace(/[^a-z0-9]/gi, '_').toLowerCase();
        doc.save('violator_profile_' + safeName + '.pdf');
    }

    window.setupViolatorPdfDownload = function(options) {
        var buttonId = options && options.buttonId ? options.buttonId : '';
        var payload = options && options.payload ? options.payload : null;
        var successMessage = options && options.successMessage ? options.successMessage : 'PDF report generated successfully.';
        var button = document.getElementById(buttonId);

        if (!button) {
            return;
        }
        if (!payload) {
            button.addEventListener('click', function() {
                alert('No violator profile is currently loaded. Select a violator first.');
            });
            return;
        }
        if (!window.jspdf || !window.jspdf.jsPDF) {
            button.addEventListener('click', function() {
                alert('PDF library failed to load. Please refresh the page and try again.');
            });
            return;
        }

        button.addEventListener('click', async function() {
            button.disabled = true;
            var originalText = button.textContent;
            button.textContent = 'Generating PDF...';
            try {
                await createViolatorPdf(payload);
                alert(successMessage);
            } finally {
                button.disabled = false;
                button.textContent = originalText;
            }
        });
    };
})();
