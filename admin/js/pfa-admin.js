/**
 * Admin JavaScript for Product Feed Automation
 *
 * @package    Product_Feed_Automation
 */

(function($) {
    'use strict';

    // Initialize when document is ready
    $(document).ready(function() {
        let countdownInterval;
        let currentStatus = {};

        // Initialize countdowns and periodic refresh
        initializeCountdowns();
        
        /**
         * Initialize countdowns and periodic refreshes
         */
        function initializeCountdowns() {
            // Clear existing interval if any
            clearInterval(countdownInterval);
            
            // Set up countdown timer
            countdownInterval = setInterval(updateCountdowns, 1000);
            
            // Auto-refresh status every 5 minutes
            setInterval(refreshQueueStatus, 300000);
            
            // Initial status refresh
            refreshQueueStatus();
        }

        /**
         * Refresh queue status via AJAX
         */
        function refreshQueueStatus() {
            console.log("Refreshing queue status...");
            $("#refresh-queue-status").prop("disabled", true).text("Refreshing...");
            
            $.ajax({
                url: pfaData.ajaxurl,
                type: "POST",
                data: {
                    action: "pfa_refresh_status",
                    nonce: pfaData.nonce
                },
                success: function(response) {
                    console.log("AJAX Response:", response);
                    if (response.success && response.data) {
                        updateStatusDisplay(response.data);
                    } else {
                        console.error("Error in AJAX Response:", response);
                    }
                },
                error: function(xhr, status, error) {
                    console.error("Server error while refreshing queue status:", error);
                },
                complete: function() {
                    $("#refresh-queue-status").prop("disabled", false).text("Refresh Status");
                }
            });
        }

        /**
         * Update countdown timers
         */
        function updateCountdowns() {
            if (!currentStatus) return;

            $(".pfa-countdown-timer").each(function() {
                const $timer = $(this);
                const $statusTime = $timer.siblings(".pfa-next-dripfeed-time, .pfa-next-daily-time");
                const targetTimeStr = $statusTime.text();

                // Don't show countdown for status messages
                if (
                    targetTimeStr.includes("Paused") ||
                    targetTimeStr.includes("limit reached") ||
                    targetTimeStr.includes("posts scheduled")
                ) {
                    $timer.text("");
                    return;
                }

                if (!targetTimeStr || targetTimeStr === "Not scheduled") {
                    $timer.text("Not scheduled");
                    return;
                }

                // Only show countdown for actual timestamps
                if (targetTimeStr.match(/\d{4}-\d{2}-\d{2}/)) {
                    const targetTime = moment.tz(targetTimeStr, pfaData.wp_timezone);
                    if (!targetTime.isValid()) {
                        $timer.text("");
                        return;
                    }

                    const now = moment();
                    const duration = moment.duration(targetTime.diff(now));

                    if (duration.asSeconds() <= 0) {
                        $timer.text("Checking...");
                        refreshQueueStatus();
                    } else {
                        $timer.text(
                            Math.floor(duration.asHours()) +
                            "h " +
                            duration.minutes() +
                            "m " +
                            duration.seconds() +
                            "s"
                        );
                    }
                } else {
                    $timer.text("");
                }
            });
        }

        /**
         * Update status display with new data
         */
        function updateStatusDisplay(data) {
            if (!data) return;

            // Store current status
            currentStatus = data;

            // Log the full data to confirm its structure
            console.log("Status Data:", data);

            // Update automation status
            const $statusIndicator = $("#automation_status_indicator");
            let statusClass = data.automation_enabled ? "active" : "inactive";
            let statusText = data.automation_enabled ? "Active" : "Paused";

            if (data.is_restricted_time) {
                statusClass = "inactive";
                statusText = "Paused (Night hours)";
            }

            console.log("Automation Status:", statusClass, statusText);
            $statusIndicator
                .removeClass("active inactive")
                .addClass(statusClass)
                .find("span")
                .text(statusText);

            // Update counters
            console.log("Posts Today:", data.posts_today, "/", data.max_posts);
            $(".pfa-posts-today").text(
                `${data.posts_today} / ${data.max_posts}`
            );

            // Update queue size
            $(".pfa-queue-size").text(data.queue_size);

            // Update scheduled posts information
            console.log(
                "Scheduled Posts:",
                data.scheduled_posts,
                "Status Message:",
                data.status_message
            );
            if (data.scheduled_posts > 0) {
                $(".pfa-scheduled-posts-count").html(`
                    <strong>${data.scheduled_posts}</strong> posts scheduled<br>
                    <small>${data.status_message || ""}</small>
                `);
            } else {
                $(".pfa-scheduled-posts-count")
                    .text("No posts scheduled")
                    .removeAttr("title");
            }

            // Update API check information
            console.log("API Check:", data.api_check);
            if (data.api_check) {
                $(".pfa-next-api-check").text(data.api_check.next_check || "Not scheduled");

                let lastCheckHtml = "No check performed yet";
                if (data.api_check.last_check_time && data.api_check.last_check_time !== 'Not Set') {
                    lastCheckHtml = `Found ${
                        data.api_check.eligible_products || 0
                    } products with 
                        ${data.api_check.min_discount || 0}%+ discount
                        (of ${data.api_check.total_products || 0} total)<br>
                        <small>Checked at: ${data.api_check.last_check_time}</small>`;
                }
                $(".pfa-last-check-results").html(lastCheckHtml);
            } else {
                $(".pfa-next-api-check").text("Not scheduled");
                $(".pfa-last-check-results").text("No check performed yet");
            }

            // Update archive stats
            console.log("Archive Stats:", data.archived_stats);
            if (data.archived_stats) {
                $(".pfa-archive-stats").html(`
                    ${data.archived_stats.total || 0} total archived
                    ${
                        data.archived_stats.recent > 0
                        ? `<br><small>${data.archived_stats.last_24h}</small>`
                        : ""
                    }
                `);
            } else {
                $(".pfa-archive-stats").text("0 total archived");
            }

            // Update next daily check if available
            console.log("Next Daily Check:", data.next_daily);
            if (data.next_daily) {
                $(".pfa-next-daily-time")
                    .text(data.next_daily)
                    .siblings(".pfa-countdown-timer")
                    .attr(
                        "data-target",
                        moment.tz(data.next_daily, pfaData.wp_timezone).format()
                    );
            }

            // Show refresh message
            const $message = $("<div>")
                .addClass("notice notice-success")
                .html("<p>Status refreshed successfully</p>")
                .insertAfter("#refresh-queue-status")
                .delay(2000)
                .fadeOut(function() {
                    $(this).remove();
                });
        }

        /**
         * Refresh status button handler
         */
        $("#refresh-queue-status").on("click", function() {
            const $button = $(this);
            $button.prop("disabled", true).text("Refreshing...");

            $.ajax({
                url: pfaData.ajaxurl,
                type: "POST",
                data: {
                    action: "pfa_refresh_status",
                    nonce: pfaData.nonce
                },
                success: function(response) {
                    if (response.success && response.data) {
                        updateStatusDisplay(response.data);
                    }
                },
                complete: function() {
                    $button.prop("disabled", false).text("Refresh Status");
                }
            });
        });

        /**
         * Setup schedules button handler
         */
        $("#setup-schedules").on("click", function() {
            var $button = $(this);
            $button.prop("disabled", true).text("Setting up schedules...");

            $.ajax({
                url: pfaData.ajaxurl,
                type: "POST",
                data: {
                    action: "setup_schedules",
                    nonce: pfaData.nonce
                },
                success: function(response) {
                    if (response.success) {
                        updateStatusDisplay(response.data);
                        location.reload();
                    } else {
                        alert(
                            "Failed to setup schedules: " +
                            (response.data.message || "Unknown error")
                        );
                    }
                },
                error: function() {
                    alert("Server error while setting up schedules");
                },
                complete: function() {
                    $button.prop("disabled", false).text("Setup Schedules");
                }
            });
        });

        /**
         * Toggle Automation Status
         */
        $("#automation_status").on("change", function() {
            const newStatus = $(this).val();

            $.ajax({
                url: pfaData.ajaxurl,
                type: "POST",
                data: {
                    action: "pfa_toggle_automation",
                    status: newStatus,
                    nonce: pfaData.nonce
                },
                success: function(response) {
                    if (response.success) {
                        refreshQueueStatus();
                    }
                }
            });
        });

        /**
         * Check Discount Results
         */
        $("#check_discount_results").on("click", function(e) {
            e.preventDefault();
        
            const $button = $(this);
            const minDiscount = $("#min_discount").val() || 0;
        
            $(".pfa-discount-check-message").remove();
        
            $button
                .prop("disabled", true)
                .html(
                    '<span class="dashicons dashicons-update pfa-spinning"></span> Checking...'
                );
        
            $.ajax({
                url: pfaData.ajaxurl,
                type: "POST",
                data: {
                    action: "pfa_check_discount_results",
                    min_discount: minDiscount,
                    nonce: pfaData.nonce
                },
                success: function(response) {
                    console.log("Discount check response:", response);
                    const $message = $("<div>")
                        .addClass("pfa-discount-check-message notice")
                        .css({
                            "margin-top": "10px",
                            padding: "10px 15px",
                            "border-left-width": "4px"
                        });
        
                    if (response.success) {
                        let messageContent = `
                            <p>
                                <strong>Discount Check Results:</strong><br>
                                • Found ${response.data.total_hits} in-stock products with ${response.data.min_discount}%+ discount<br>
                                • Out of ${response.data.in_stock_count} available products (${response.data.total_products} total)<br>
                                • Next scheduled check: ${response.data.next_scheduled_check}
                            </p>
                        `;
        
                        if (response.data.sample_products?.length > 0) {
                            messageContent += `
                                <p><strong>Top Discounted Products:</strong></p>
                                <ul style="list-style-type: disc; margin-left: 20px;">
                                    ${response.data.sample_products
                                        .map(
                                            (product) => `
                                            <li>
                                                ${product.title}<br>
                                                <small>
                                                    Original: ${product.original_price} | 
                                                    Sale: ${product.sale_price} | 
                                                    Discount: ${product.discount}
                                                </small>
                                            </li>
                                        `
                                        )
                                        .join("")}
                                </ul>
                            `;
                        }
        
                        $message.addClass("notice-success").html(messageContent).show();
        
                        // Note we don't update the last check results display
                        // to avoid implying the settings have been saved
                        
                    } else {
                        $message
                            .addClass("notice-error")
                            .html(
                                `<p>Error: ${
                                    response.data.message || "Unknown error occurred"
                                }</p>`
                            );
                    }
        
                    $message.insertAfter($button.closest("tr")).show();
                },
                error: function(xhr, status, error) {
                    console.error("Discount check error:", xhr, status, error);
                    const $message = $("<div>")
                        .addClass("pfa-discount-check-message notice notice-error")
                        .css({
                            "margin-top": "10px",
                            padding: "10px 15px"
                        })
                        .html(`<p>Server error occurred: ${error}</p>`)
                        .insertAfter($button.closest("tr"))
                        .show();
                },
                complete: function() {
                    $button.prop("disabled", false).html("Check Results");
                }
            });
        });

        /**
         * Settings Form Submission
         */
        $("#pfa-settings-form").on("submit", function(e) {
            e.preventDefault();
            const formData = $(this).serialize();

            $.ajax({
                url: pfaData.ajaxurl,
                type: "POST",
                data: `${formData}&action=save_ai_workflow_settings&nonce=${pfaData.nonce}`,
                success: function(response) {
                    const $message = $("#pfa-settings-message");

                    if (response.success) {
                        $message
                            .removeClass("notice-error")
                            .addClass("notice notice-success")
                            .html("<p>Settings saved successfully.</p>")
                            .show()
                            .delay(3000)
                            .fadeOut();

                        // Update status display with new data
                        if (response.data.status) {
                            updateStatusDisplay(response.data.status);

                            // Update the check interval text immediately
                            const intervalText = response.data.check_interval || "daily";
                            $(".pfa-next-api-check")
                                .closest(".pfa-api-status")
                                .find(".pfa-check-interval-text")
                                .text(`(${intervalText} checks)`);
                        }

                        // Force an immediate status refresh
                        refreshQueueStatus();

                        setTimeout(function() {
                            location.reload();
                        }, 2500);
                    } else {
                        $message
                            .removeClass("notice-success")
                            .addClass("notice notice-error")
                            .html(
                                `<p>Error saving settings: ${
                                    response.data?.message || "Unknown error"
                                }</p>`
                            )
                            .show();
                    }
                }
            });
        });

        /**
         * Manual Post Creation Form Submission
         */
        $("#pfa-manual-post-form").on("submit", function(e) {
            e.preventDefault();
            var formData = $(this).serialize();
            var $submitButton = $(this).find('button[type="submit"]');
            
            $submitButton.prop('disabled', true);

            $.ajax({
                url: pfaData.ajaxurl,
                type: "POST",
                data: formData + "&action=pfa_create_manual_post&nonce=" + pfaData.nonce,
                success: function(response) {
                    const $message = $("<div>").addClass("notice");

                    if (response.success) {
                        $message
                            .addClass("notice-success")
                            .html(
                                `<p>Post created successfully. Post ID: ${response.data.post_id}</p>`
                            );
                        $("#pfa-manual-post-form")[0].reset();
                    } else {
                        $message
                            .addClass("notice-error")
                            .html(
                                `<p>Error creating post: ${
                                    response.data.message || "Unknown error occurred"
                                }</p>`
                            );
                    }

                    $message
                        .insertAfter("#pfa-manual-post-form")
                        .fadeIn()
                        .delay(3000)
                        .fadeOut();
                },
                complete: function() {
                    $submitButton.prop("disabled", false);
                }
            });
        });

        /**
         * Number Input Validation
         */
        $('input[type="number"]').on("input", function() {
            const min = parseFloat($(this).attr("min"));
            const max = parseFloat($(this).attr("max"));
            const value = parseFloat($(this).val());

            if (value < min) {
                $(this).val(min);
            } else if (max && value > max) {
                $(this).val(max);
            }
        });

        // Initialize by getting initial status
        refreshQueueStatus();
    });
})(jQuery);


$(window).on("load", function() {
    // Short delay to ensure DOM is fully loaded
    setTimeout(function() {
        refreshQueueStatus();
    }, 500);
});