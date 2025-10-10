# Custom Magento 2 Quote Module

## Overview
The **Custom Magento 2 Quote Module** (`Gelmar_QuoteGenerator`) is a custom-developed module for Magento 2 Open Source (version 2.4.7-p3 and above) that enhances the checkout process by allowing users to generate quotes directly from the checkout page. It also provides functionality for users to view their quote history and interact with individual quotes in their customer account. The module is designed to streamline quote management for in-store purchases, ensuring a seamless user experience while adhering to Magento's coding standards.

## Features
- **Checkout Quote Generation**:
  - Adds a "Generate Quote" button to the checkout page, positioned after the terms and conditions block.
  - Validates user actions (e.g., terms acceptance, login status, shipping method, and store selection) before generating a quote.
  - Displays user-friendly modal popups for validation errors (e.g., requiring login, selecting Click & Collect, or choosing a store).
  - Generates a downloadable PDF quote for in-store purchases, making the current cart inactive and redirecting to the homepage.
- **Quote History**:
  - Provides a dedicated page in the customer account to view a list of previously generated quotes.
  - Accessible via the customer account navigation menu.
- **Quote Viewing**:
  - Allows users to view details of individual quotes, including associated products.
  - Supports adding quote items back to the cart for further processing.
- **Responsive Design**:
  - Includes a clean, modern modal popup design with CSS styling for a consistent user experience.
  - Uses a separate CSS file (`quote-generator.css`) for maintainability and performance.
- **Backend Integration**:
  - Generates PDF quotes using a custom `PdfGenerator` model.
  - Supports JSON responses for error handling and redirects (e.g., login prompts).

## Requirements
- Magento Open Source 2.4.x
- PHP 8.1 or higher
- Apache2
- MySQL

## Installation
1. **Clone or Download the Module**:
   ```bash
   git clone https://github.com/Rorke-Melville/Magento2_Custom_Quote-Module.git
   ```
   Or download the module files and place them in `app/code/Gelmar/QuoteGenerator`.

2. **Enable the Module**:
   Run the following Magento commands from the root of your Magento installation:
   ```bash
   php bin/magento setup:upgrade
   php bin/magento setup:di:compile
   php bin/magento setup:static-content:deploy -f
   php bin/magento cache:clean
   ```

3. **Verify Installation**:
   - Ensure the module is enabled by checking `php bin/magento module:status Gelmar_QuoteGenerator`.
   - Confirm the "Generate Quote" button appears on the checkout page and the "Quote History" link is available in the customer account.

## Usage
### Generating a Quote
1. Navigate to the checkout page with items in your cart.
2. Ensure you are logged in and have selected the "Click & Collect" shipping method and a store.
3. Accept the terms and conditions.
4. Click the "Generate Quote" button.
5. Confirm the quote generation in the modal popup (titled "Quote Notice").
6. Download the generated PDF quote, which will redirect you to the homepage after clearing the cart.

### Viewing Quote History
1. Log in to your customer account.
2. Navigate to the "Quote History" section via the account sidebar.
3. View a list of all previously generated quotes.

### Viewing Individual Quotes
1. From the "Quote History" page, click on a quote to view its details.
2. Use the "Add to Cart" option to restore quote items to your cart for further processing.

## File Structure
```
app/code/Gelmar/QuoteGenerator/
├── Controller/
│   ├── Cart/
│   │   ├── AddAll.php
│   │   └── GenerateQuote.php
│   ├── Quote/
│   │   ├── History.php
│   │   └── View.php
├── etc/
│   ├── di.xml
│   ├── module.xml
│   ├── frontend/
│   │   ├── customer.xml
│   │   └── routes.xml
├── Model/
│   └── PdfGenerator.php
├── view/
│   ├── frontend/
│   │   ├── layout/
│   │   │   ├── checkout_index_index.xml
│   │   │   ├── customer_account.xml
│   │   │   ├── default.xml
│   │   │   ├── quote_quote_history.xml
│   │   │   └── quote_quote_view.xml
│   │   ├── templates/
│   │   │   ├── quote/
│   │   │   │   ├── history.phtml
│   │   │   │   └── view.phtml
│   │   ├── web/
│   │   │   ├── css/
│   │   │   │   └── quote-generator.css
│   │   │   ├── js/
│   │   │   │   └── view/
│   │   │   │       └── quote-generator.js
│   │   │   └── template/
│   │   │       └── quote-generator
````

## Technical Details
- **Frontend**:
  - Uses KnockoutJS for the checkout page component (`quote-generator.js` and `quote-generator.html`).
  - Implements a custom modal popup with CSS styling for user validations and confirmations.
  - Integrates with Magento’s checkout and customer data APIs for quote generation and user authentication.
- **Backend**:
  - Utilizes a custom controller (`GenerateQuote.php`) for handling AJAX requests to generate PDF quotes.
  - Employs `PdfGenerator.php` for PDF creation, compatible with Magento’s backend architecture.
- **Styling**:
  - CSS is managed in `quote-generator.css`, loaded via `checkout_index_index.xml` for optimal performance.
  - Includes responsive modal designs with hover effects and smooth transitions.

## Development Notes
- The module follows Magento 2 coding standards, with separate concerns for JavaScript (behavior), CSS (styling), and PHP (backend logic).
- The checkout quote generation requires the "Click & Collect" shipping method (`flatrate_flatrate`) and a selected store.
- Error handling includes user-friendly messages for login requirements, shipping method issues, and store selection.

## Contributing
Contributions are welcome! Please submit a pull request or open an issue on the [GitHub repository](https://github.com/Rorke-Melville/Magento2_Custom_Quote-Module) for bug reports, feature requests, or improvements.
