jQuery(document).ready(function ($) {
  // let refreshInterval;
  let countdownInterval;
  let currentStatus = {};

  // startAutoRefresh();
  initializeCountdowns();

  function initializeCountdowns() {
    clearInterval(countdownInterval);
    countdownInterval = setInterval(updateCountdowns, 1000); 
    
    setInterval(refreshQueueStatus, 300000); 
    
    refreshQueueStatus();
}

  function refreshQueueStatus() {
    console.log("Refreshing queue status...");
    $.ajax({
      url: pfaAjax.ajaxurl,
      type: "POST",
      data: {
        action: "pfa_check_dripfeed_status",
        nonce: pfaAjax.nonce,
      },
      success: function (response) {
        console.log("AJAX Response:", response);
        if (response.success && response.data) {
          updateStatusDisplay(response.data);
        } else {
          console.error("Error in AJAX Response:", response);
        }
      },
      error: function (xhr, status, error) {
        console.error("Server error while refreshing queue status:", error);
      },
    });
  }

  function updateCountdowns() {
    if (!currentStatus) return;

    $(".countdown-timer").each(function () {
      const $timer = $(this);
      const $statusTime = $timer.siblings(
        ".next-dripfeed-time, .next-daily-time"
      );
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
        const targetTime = moment.tz(targetTimeStr, pfaAjax.wp_timezone);
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

  function updateStatusDisplay(data) {
    if (!data) return;

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
    $(".posts-today").text(
      `${data.posts_today} / ${data.max_posts} posts today`
    );

    // Update scheduled posts information
    console.log(
      "Scheduled Posts:",
      data.scheduled_posts,
      "Status Message:",
      data.status_message
    );
    if (data.scheduled_posts > 0) {
      $(".scheduled-posts-count").html(`
          <strong>${data.scheduled_posts}</strong> posts scheduled<br>
          <small>${data.status_message || ""}</small>
      `);
    } else {
      $(".scheduled-posts-count")
        .text("No posts scheduled")
        .removeAttr("title");
    }

    // Update API check information
    console.log("API Check:", data.api_check);
    if (data.api_check) {
      $(".next-api-check").text(data.api_check.next_check || "Not scheduled");

      let lastCheckHtml = "No check performed yet";
      if (data.api_check.last_check_time) {
        lastCheckHtml = `Found ${
          data.api_check.eligible_products || 0
        } products with 
              ${data.api_check.min_discount || 0}%+ discount
              (of ${data.api_check.total_products || 0} total)<br>
              <small>Checked at: ${data.api_check.last_check_time}</small>`;
      }
      $(".last-check-results").html(lastCheckHtml);
    } else {
      $(".next-api-check").text("Not scheduled");
      $(".last-check-results").text("No check performed yet");
    }

    // Update archive stats
    console.log("Archive Stats:", data.archived_stats);
    if (data.archived_stats) {
      $(".archive-stats").html(`
          ${data.archived_stats.total || 0} total archived
          ${
            data.archived_stats.recent > 0
              ? `<br><small>${data.archived_stats.last_24h}</small>`
              : ""
          }
      `);
    } else {
      $(".archive-stats").text("0 total archived");
    }

    // Update next daily check if available
    console.log("Next Daily Check:", data.next_daily);
    if (data.next_daily) {
      $(".next-daily-time")
        .text(data.next_daily)
        .siblings(".countdown-timer")
        .attr(
          "data-target",
          moment.tz(data.next_daily, pfaAjax.wp_timezone).format()
        );
    }

    // Show refresh message
    const $message = $("<div>")
      .addClass("notice notice-success")
      .html("<p>Status refreshed successfully</p>")
      .insertAfter("#refresh-queue-status")
      .delay(2000)
      .fadeOut(function () {
        $(this).remove();
      });
  }

  // Update the refresh handler
  $("#refresh-queue-status").on("click", function () {
    const $button = $(this);
    $button.prop("disabled", true).text("Refreshing...");

    $.ajax({
      url: pfaAjax.ajaxurl,
      type: "POST",
      data: {
        action: "refresh_queue_status",
        nonce: pfaAjax.nonce,
      },
      success: function (response) {
        if (response.success && response.data) {
          updateStatusDisplay(response.data);
          // startAutoRefresh();
        }
      },
      complete: function () {
        $button.prop("disabled", false).text("Refresh Status");
      },
    });
  });

  $("#setup-schedules").on("click", function () {
    var $button = $(this);
    $button.prop("disabled", true).text("Setting up schedules...");

    $.ajax({
      url: pfaAjax.ajaxurl,
      type: "POST",
      data: {
        action: "setup_schedules",
        nonce: pfaAjax.nonce,
      },
      success: function (response) {
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
      error: function () {
        alert("Server error while setting up schedules");
      },
      complete: function () {
        $button.prop("disabled", false).text("Setup Schedules");
      },
    });
  });

  // Toggle Automation Status
  $("#automation_status").on("change", function () {
    const newStatus = $(this).val();

    $.ajax({
      url: pfaAjax.ajaxurl,
      type: "POST",
      data: {
        action: "pfa_toggle_automation",
        status: newStatus,
        nonce: pfaAjax.nonce,
      },
      success: function (response) {
        if (response.success) {
          refreshQueueStatus();
        }
      },
    });
  });

  // Check Discount Results
  $("#check_discount_results").on("click", function (e) {
    e.preventDefault();

    const $button = $(this);
    const $parent = $button.closest("tr");
    const minDiscount = $("#min_discount").val() || 0;

    $(".discount-check-message").remove();

    $button
      .prop("disabled", true)
      .html(
        '<span class="dashicons dashicons-update spinning"></span> Checking...'
      );

    $.ajax({
      url: pfaAjax.ajaxurl,
      type: "POST",
      data: {
        action: "pfa_check_discount_results",
        min_discount: minDiscount,
        nonce: pfaAjax.nonce,
      },
      success: function (response) {
        const $message = $("<div>")
          .addClass("discount-check-message notice")
          .css({
            "margin-top": "10px",
            padding: "10px 15px",
            "border-left-width": "4px",
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

          $message.addClass("notice-success").html(messageContent).show()
          .delay(3000)
          .fadeOut();

          // Update the last check results display
          $(".last-check-results").html(`
                    Found ${response.data.total_hits} products with 
                    ${response.data.min_discount}%+ discount
                    (of ${response.data.total_products} total)<br>
                    <small>Checked at: ${response.data.last_check_time}</small>
                `);
        } else {
          $message
            .addClass("notice-error")
            .html(
              `<p>Error: ${
                response.data.message || "Unknown error occurred"
              }</p>`
            );
        }

        $message.insertAfter($parent).show();

        $(".last-check-results")
          .css("background-color", "#ffd")
          .delay(1000)
          .queue(function (next) {
            $(this).css("background-color", "");
            next();
          });
      },
      error: function (xhr, status, error) {
        const $message = $("<div>")
          .addClass("discount-check-message notice notice-error")
          .css({
            "margin-top": "10px",
            padding: "10px 15px",
          })
          .html(`<p>Server error occurred: ${error}</p>`)
          .insertAfter($parent)
          .show();
      },
      complete: function () {
        $button.prop("disabled", false).html("Check Results");
      },
    });
  });

  // Settings Form Submission
  $("#settings-form").on("submit", function (e) {
    e.preventDefault();
    const formData = $(this).serialize();

    $.ajax({
      url: pfaAjax.ajaxurl,
      type: "POST",
      data: `${formData}&action=save_ai_workflow_settings&nonce=${pfaAjax.nonce}`,
      success: function (response) {
        const $message = $("#settings-message");

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
            $(".next-api-check")
              .closest(".api-status")
              .find(".check-interval-text")
              .text(`(${intervalText} checks)`);
          }

          // Force an immediate status refresh
          refreshQueueStatus();

          setTimeout(function () {
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
      },
    });
  });

  // Initialize by getting initial status
  refreshQueueStatus();

  // Manual Post Creation Form Submission
  $("#manual-post-form").on("submit", function (e) {
    e.preventDefault();
    var formData = $(this).serialize();

    $.ajax({
      url: pfaAjax.ajaxurl,
      type: "POST",
      data: formData + "&action=pfa_create_manual_post&nonce=" + pfaAjax.nonce,
      success: function (response) {
        const $message = $("<div>").addClass("manual-post-message notice");

        if (response.success) {
          $message
            .addClass("notice-success")
            .html(
              `<p>Post created successfully. Post ID: ${response.data.post_id}</p>`
            );
          $("#manual-post-form")[0].reset();
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
          .insertAfter("#manual-post-form")
          .fadeIn()
          .delay(3000)
          .fadeOut();
      },
      complete: function () {
        $("#manual-post-form button[type='submit']").prop("disabled", false);
      },
    });
  });

  // Number Input Validation
  $('input[type="number"]').on("input", function () {
    const min = parseFloat($(this).attr("min"));
    const max = parseFloat($(this).attr("max"));
    const value = parseFloat($(this).val());

    if (value < min) {
      $(this).val(min);
    } else if (max && value > max) {
      $(this).val(max);
    }
  });

//   $("#force-api-check").on("click", function() {
//     const $button = $(this);
//     $button.prop("disabled", true).text("Checking...");

//     $.ajax({
//         url: pfaAjax.ajaxurl,
//         type: "POST",
//         data: {
//             action: "pfa_force_api_check",
//             nonce: pfaAjax.nonce
//         },
//         success: function(response) {
//             if (response.success) {
//                 alert("API check completed");
//                 // Refresh status display
//                 $("#refresh-queue-status").click();
//             }
//         },
//         error: function() {
//             alert("Error during API check");
//         },
//         complete: function() {
//             $button.prop("disabled", false).text("Force API Check");
//         }
//     });
// });
});
