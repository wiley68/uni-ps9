function calculateUni(_uni_meseci) {
    if (!$("#link_to_calculateuni").length || !$("#link_to_session").length) {
        return;
    }
    $("body").css("pointer-events", function (index) {
        return "none";
    });
    $.ajax({
        url: $("#link_to_calculateuni").val(),
        type: "post",
        dataType: "json",
        data: {
            uni_promo: $("#uni_promo").val(),
            uni_promo_data: $("#uni_promo_data").val(),
            uni_promo_meseci_znak: $("#uni_promo_meseci_znak").val(),
            uni_promo_meseci: $("#uni_promo_meseci").val(),
            uni_promo_price: $("#uni_promo_price").val(),
            uni_product_cat_id: $("#uni_product_cat_id").val(),
            uni_product_category_ids: $("#uni_product_category_ids").val(),
            uni_meseci: _uni_meseci,
            uni_total_price: $("#uni_price").val(),
            uni_service: $("#uni_service").val(),
            uni_parva: $("#uni_parva").val(),
            uni_user: $("#uni_user").val(),
            uni_password: $("#uni_password").val(),
            uni_sertificat: $("#uni_sertificat").val(),
            uni_liveurl: $("#uni_liveurl").val(),
            uni_real_ip: $("#uni_real_ip").val(),
            uni_unicid: $("#uni_unicid").val(),
            uni_eur: $("#uni_eur").val(),
        },
        success: function (json) {
            if (!json || !json.result) {
                $("body").css("pointer-events", function (index) {
                    return "";
                });
                return;
            }
            $("#uni_mesecna").val(json.result.uni_mesecna);
            if (json.result.uni_mesecna_second == 0) {
                document.getElementById("uni_mesecna_second").value =
                    json.result.uni_mesecna;
            } else {
                document.getElementById("uni_mesecna_second").value =
                    json.result.uni_mesecna +
                    " (" +
                    json.result.uni_mesecna_second +
                    ")";
            }
            $("#uni_glp").val(json.result.uni_glp);
            $("#uni_obshto").val(json.result.uni_obshto);
            if (json.result.uni_obshto_second == 0) {
                document.getElementById("uni_obshto_second").value =
                    json.result.uni_obshto;
            } else {
                document.getElementById("uni_obshto_second").value =
                    json.result.uni_obshto +
                    " (" +
                    json.result.uni_obshto_second +
                    ")";
            }
            $("#uni_obshtozaplashtane").val(json.result.uni_obshtozaplashtane);
            if (json.result.uni_obshtozaplashtane_second == 0) {
                document.getElementById("uni_obshtozaplashtane_second").value =
                    json.result.uni_obshtozaplashtane;
            } else {
                document.getElementById("uni_obshtozaplashtane_second").value =
                    json.result.uni_obshtozaplashtane +
                    " (" +
                    json.result.uni_obshtozaplashtane_second +
                    ")";
            }
            $("#uni_gpr").val(json.result.uni_gpr);
            let uni_cop_var = json.result.uni_kop;
            // save session vars
            $.ajax({
                url: $("#link_to_session").val(),
                type: "POST",
                dataType: "json",
                headers: { "cache-control": "no-cache" },
                async: true,
                cache: false,
                data: {
                    add: 1,
                    uni_mesecna: $("#uni_mesecna").val(),
                    uni_gpr_input: $("#uni_gpr").val(),
                    uni_parva_input: $("#uni_parva").val(),
                    uni_obshtozaplashtane_input: $(
                        "#uni_obshtozaplashtane",
                    ).val(),
                    uni_glp_input: $("#uni_glp").val(),
                    uni_vnoski: _uni_meseci,
                    uni_fname: $("#uni_fname").val(),
                    uni_lname: $("#uni_lname").val(),
                    uni_phone: $("#uni_phone").val(),
                    uni_phone2: $("#uni_phone2").val(),
                    uni_email: $("#uni_email").val(),
                    uni_egn: $("#uni_egn").val(),
                    uni_description: $("#uni_description").val(),
                    uni_kop: uni_cop_var,
                    uni_uslovia: $("#uni_uslovia").prop("checked"),
                    uni_proces2: $("#uni_proces2").val(),
                },
                success: function (jsonData) {
                    //start mouse click
                    $("body").css("pointer-events", function (index) {
                        return "";
                    });
                },
                error: function (response) {
                    //start mouse click
                    $("body").css("pointer-events", function (index) {
                        return "";
                    });
                },
            });
        },
        error: function (response) {
            //start mouse click
            $("body").css("pointer-events", function (index) {
                return "";
            });
        },
    });
}
$(document).ready(function (e) {
    if (!$("#uni_shema_current").length) {
        return;
    }
    if (parseInt($("#uni_proces2").val()) > 0) {
        var _ucs =
            typeof window.unipaymentCheckoutStrings === "object" &&
            window.unipaymentCheckoutStrings !== null
                ? window.unipaymentCheckoutStrings
                : {};
        function _ucMsg(k, fallback) {
            return _ucs[k] || fallback;
        }
        if (parseInt($("#uni_check").val(), 10) == 1) {
            alert(
                _ucMsg(
                    "mustAgreeTerms",
                    "You must agree to the UniCredit terms and conditions.",
                ),
            );
        }
        if (parseInt($("#uni_fname_get").val(), 10) == 1) {
            alert(
                _ucMsg("fillFirstName", "Please fill in the First name field."),
            );
        }
        if (parseInt($("#uni_lname_get").val(), 10) == 1) {
            alert(
                _ucMsg("fillLastName", "Please fill in the Last name field."),
            );
        }
        if (parseInt($("#uni_egn_get").val(), 10) == 1) {
            alert(
                _ucMsg(
                    "fillPersonalId",
                    "Please fill in the Personal ID field.",
                ),
            );
        }
        if (parseInt($("#uni_phone_get").val(), 10) == 1) {
            alert(_ucMsg("fillPhone", "Please fill in the Phone field."));
        }
        if (parseInt($("#uni_email_get").val(), 10) == 1) {
            alert(_ucMsg("fillEmail", "Please fill in the E-mail field."));
        }
    }
    calculateUni($("#uni_shema_current").val());

    $("#uni_pogasitelni_vnoski").change(function () {
        calculateUni($("#uni_pogasitelni_vnoski").val());
    });
    $("#uni_uslovia").click(function () {
        calculateUni($("#uni_pogasitelni_vnoski").val());
    });
    $("#uni_parva_chec").change(function () {
        if ($("#uni_parva_chec").prop("checked")) {
            $("#uni_parva").attr("readonly", false);
        } else {
            $("#uni_parva").attr("readonly", true);
        }
    });
    $("#uni_parva_button").click(function () {
        calculateUni($("#uni_pogasitelni_vnoski").val());
    });
});
