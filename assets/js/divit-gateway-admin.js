jQuery(function ($) {
  /**
   * check installation status in admin page
   * if already success then do not need call anything.
   * click test button then call api, after n seconds check again.
   */

  var installationStatus = wcdivit.installationStatus;

  var $installationStatusField = $("#installationStatusField");
  var $testConnectionButton = $(
    '<button id="webhook-test-button" class="components-button is-primary">test connection</button>',
  );

  if (installationStatus !== "success") {
    $installationStatusField.append($testConnectionButton);
  }

  function setInstallationStatus(status) {
    $testConnectionButton.attr("disabled", false);
    var text = status === "success" ? "success" : "webhook test not done";
    $("#installationStatusText").text(text);
  }

  function getInstallationStatus(callback) {
    $.ajax({
      method: "POST",
      url: wcdivit.ajaxUrl,
      data: {
        action: "divit_get_installation_status",
        nonce: wcdivit.nonce,
      },
      success: function (data) {
        var status = data.data.installationStatus;
        callback(status);
      },
    });
  }

  $testConnectionButton.on("click", function () {
    $testConnectionButton.attr("disabled", "true");
    $("#installationStatusText").text("testing...");

    // check status or testing after n seconds
    getInstallationStatus(function (status) {
      if (status === "success") {
        setInstallationStatus(status);
      } else {
        // check againg after status
        setTimeout(() => {
          getInstallationStatus(function (status) {
            setInstallationStatus(status);
          });
        }, 5 * 1000);
      }
    });
  });

  // check status again for the first time
  setTimeout(function () {
    setInstallationStatus(installationStatus);
  }, 2 * 1000);

  // Lock and add warning note for 'divit_order_id' custom field
  function lockDivitOrderIdField() {
    var $customFieldsTable = $("#postcustomstuff, #postcustom");
    if ($customFieldsTable.length) {
      $customFieldsTable.find("tr").each(function () {
        var $keyInput = $(this).find(
          'input[name^="meta"][name$="[key]"], input[value="divit_order_id"]',
        );
        if ($keyInput.length && $keyInput.val() === "divit_order_id") {
          // Make key and value inputs readonly
          $keyInput.attr("readonly", true).css("background-color", "#f0f0f1");
          var $valueTextarea = $(this).find(
            'textarea[name^="meta"][name$="[value]"], textarea',
          );
          if ($valueTextarea.length) {
            $valueTextarea
              .attr("readonly", true)
              .css("background-color", "#f0f0f1");
          }

          // Append reconciliation warning note
          if (!$(this).find(".divit-reconciliation-note").length) {
            var noteHtml =
              '<div class="divit-reconciliation-note" style="color: #d63638; font-weight: bold; margin-top: 2px; font-size: 11px;">⚠️ divit: Important for payment reconciliation. Do not delete or modify.</div>';
            if ($valueTextarea.length) {
              $valueTextarea.after(noteHtml);
            } else {
              $keyInput.after(noteHtml);
            }
          }
        }
      });
    }
  }

  // Run on initial load and periodically to handle dynamic Custom Fields box rendering/updates
  lockDivitOrderIdField();
  setInterval(lockDivitOrderIdField, 1000);
});
