<?php
/**
 * HOTFIX: Age Verification Modal CSS Inline Fallback
 *
 * Add this to your theme's functions.php as a temporary fix
 * This embeds the CSS directly instead of loading external files
 */

add_action('wp_footer', 'av_inline_css_fallback', 999);

function av_inline_css_fallback() {
    // Only run if age verification modal exists on page
    if (!isset($_COOKIE['age_verified'])) {
        ?>
        <style type="text/css">
        /* Age Verification Modal - Inline Fallback */
        #age-verification-overlay {
            position: fixed !important;
            top: 0 !important;
            left: 0 !important;
            width: 100% !important;
            height: 100% !important;
            z-index: 999998 !important;
            display: block !important;
            opacity: 0.9 !important;
        }

        #age-verification-modal {
            position: fixed !important;
            top: 50% !important;
            left: 50% !important;
            transform: translate(-50%, -50%) !important;
            z-index: 999999 !important;
            max-width: 500px !important;
            width: 90% !important;
            padding: 40px 30px !important;
            border-radius: 10px !important;
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.3) !important;
            text-align: center !important;
        }

        .age-verification-content {
            width: 100%;
        }

        .age-verification-logo {
            margin-bottom: 20px;
            max-height: 120px;
            overflow: hidden;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .age-verification-logo img {
            max-width: 180px !important;
            max-height: 100px !important;
            width: auto !important;
            height: auto !important;
            object-fit: contain !important;
        }

        .age-verification-headline {
            font-size: 28px;
            font-weight: bold;
            margin: 0 0 15px 0;
            color: #333;
        }

        .age-verification-message {
            font-size: 16px;
            color: #666;
            margin: 0 0 30px 0;
            line-height: 1.5;
        }

        .age-verification-form {
            margin: 30px 0;
        }

        .age-verification-buttons {
            display: flex;
            gap: 15px;
            justify-content: center;
            flex-wrap: wrap;
        }

        .age-verification-button {
            padding: 15px 30px !important;
            font-size: 16px !important;
            font-weight: 600 !important;
            border: none !important;
            border-radius: 5px !important;
            cursor: pointer !important;
            transition: all 0.3s ease !important;
            min-width: 150px !important;
        }

        .age-verification-button:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.2);
        }

        .age-verification-label {
            display: block;
            font-size: 16px;
            font-weight: 600;
            color: #333;
            margin-bottom: 20px;
        }

        .age-slider-wrapper {
            display: flex;
            align-items: center;
            gap: 15px;
            margin-bottom: 15px;
        }

        .age-slider {
            flex: 1;
            height: 8px;
            border-radius: 5px;
            background: #ddd;
        }

        .age-slider-display {
            text-align: center;
            margin-bottom: 20px;
        }

        .age-slider-display span {
            font-size: 18px;
            color: #333;
        }

        #age-slider-display-value {
            font-weight: bold;
            font-size: 24px;
            margin-left: 5px;
        }

        .birthdate-inputs {
            display: flex;
            gap: 10px;
            justify-content: center;
            margin-bottom: 25px;
            flex-wrap: wrap;
        }

        .birthdate-field {
            display: flex;
            flex-direction: column;
            gap: 5px;
        }

        .birthdate-input {
            padding: 10px;
            font-size: 14px;
            border: 2px solid #ddd;
            border-radius: 5px;
            background-color: #fff;
            color: #333;
            cursor: pointer;
        }

        .age-verification-error {
            margin-top: 20px;
            padding: 15px;
            background-color: #ffebee;
            color: #c62828;
            border-radius: 5px;
            font-size: 14px;
            font-weight: 600;
        }

        body.age-verification-active {
            overflow: hidden !important;
        }

        @media (max-width: 600px) {
            #age-verification-modal {
                padding: 30px 20px !important;
                max-width: 95% !important;
            }
            .age-verification-headline {
                font-size: 24px;
            }
            .age-verification-button {
                padding: 12px 20px !important;
                font-size: 14px !important;
                min-width: 120px !important;
            }
        }
        </style>
        <?php
    }
}
