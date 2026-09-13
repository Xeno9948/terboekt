# Home Terboekt - Setup Instructions

## ✅ What's Done
- Pure HTML/CSS/JavaScript website
- Multilingual support (NL, EN, FR, DE) with JavaScript
- Contact form with Formspree integration
- Responsive design
- All pages: Home, Visit Genk, Contact

## 📂 Project Structure
```
public/
├── index.html          (Home page)
├── genk.html           (Visit Genk page)
├── contact.html        (Contact/Booking form)
├── assets/
│   ├── css/
│   │   └── style.css
│   ├── js/
│   │   ├── translations.js (i18n system)
│   │   └── script.js
│   └── img/            ⚠️ NEEDS IMAGES
```

## ⚠️ Action Required

### 1. Restore Images
The WhatsApp image files were accidentally deleted. Please:
1. Copy all your `.jpeg` files back to: `public/assets/img/`
2. The filenames referenced in the HTML are:
   - `WhatsApp Image 2026-01-02 at 18.56.24.jpeg` (Hero)
   - `WhatsApp Image 2026-01-02 at 18.56.24 (1).jpeg` through `(17).jpeg` (Gallery)
   - And others from the `18.58.15` and `10.22.37` sets

### 2. Setup Formspree
1. Go to [formspree.io](https://formspree.io/)
2. Sign up (free tier available)
3. Create a new form
4. Copy your form ID
5. In `public/contact.html`, replace `YOUR_FORM_ID` with your actual ID:
   ```html
   <form action="https://formspree.io/f/YOUR_FORM_ID" method="POST">
   ```

## 🚀 How to Run Locally
```bash
cd public
python3 -m http.server 8000
```
Then open: http://localhost:8000

## 🌐 How to Deploy
Upload the entire `public/` folder to your web hosting via FTP/cPanel.

## ✨ Features
- **Language Switching**: Click NL/EN/FR/DE in header
- **Responsive**: Works on mobile/tablet/desktop
- **Fast**: No build step, no dependencies
- **SEO Friendly**: Semantic HTML, proper meta tags
