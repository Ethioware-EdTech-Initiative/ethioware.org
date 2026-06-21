# Ethioware EdTech Initiative

[![Website](https://img.shields.io/badge/Website-ethioware.org-blue)](https://ethioware.org)
[![Status](https://img.shields.io/badge/Status-Active-success)](https://ethioware.org)

> **Lessons from the doers** - Directing 200,000 African Youth to Intentionally Chosen Careers by 2040

Ethioware EdTech Initiative is a comprehensive educational platform that provides high school graduates with direct anecdotal online sessions with experts in their field of interest. The platform facilitates pre-training readiness sessions where students learn where to start, with whom to continue, and who to contact for further guidance.

## 📋 Table of Contents

- [Overview](#overview)
- [Features](#features)
- [Technology Stack](#technology-stack)
- [Project Structure](#project-structure)
- [Getting Started](#getting-started)
- [Performance Optimizations](#performance-optimizations)
- [Browser Support](#browser-support)
- [Contributing](#contributing)
- [Contact](#contact)
- [License](#license)

## 🎯 Overview

Ethioware EdTech Initiative connects high school graduates with industry experts through personalized mentorship sessions. Our mission is to help African youth make informed career decisions by providing:

- **Expert Sessions**: Direct access to professionals in various fields
- **Pre-Training Readiness**: Structured guidance on career paths
- **Research Publications**: Academic papers on educational psychology and technology
- **Practical Assessments**: Real-world skill evaluation
- **Directed Resources**: Curated learning materials

### Mission

**Direct 200,000 African Youth to Intentionally Chosen Careers by 2040**

Through motivation, adaptation, and execution, we help students:

1. Understand expert mentors' career paths
2. Learn from seniors' setbacks
3. Make actionable decisions on learning paths

## ✨ Features

### Core Features

- 📱 **Fully Responsive Design**: Optimized for mobile, tablet, and desktop
- 🌓 **Dark/Light Theme Toggle**: User preference with localStorage persistence
- 🚀 **Performance Optimized**: Lazy loading, resource hints, and deferred scripts
- 🎨 **Modern UI**: Glassmorphism design with consistent border styling
- 🔄 **Smooth Navigation**: Single-page application with browser history support
- 📊 **Interactive Statistics**: Animated counters for graduates, mentors, and partnerships
- 📝 **Contact Forms**: Integrated EmailJS for customer inquiries
- 📚 **Publications Section**: Academic papers and research publications
- 🎥 **Video Embeds**: Exclusive interviews with experts
- 🏢 **Partnership Showcase**: Animated scrolling partner logos

### Technical Features

- **SEO Optimized**: Meta tags, semantic HTML, and structured data
- **Accessibility**: ARIA labels, keyboard navigation, and screen reader support
- **Cross-Browser Compatible**: Works on all modern browsers
- **Google Analytics**: User tracking and analytics
- **Trustpilot Integration**: User reviews and ratings
- **Google One Tap Sign-In**: Seamless authentication

## 🛠 Technology Stack

### Frontend

- **HTML5**: Semantic markup
- **CSS3**: Custom properties, flexbox, grid, animations
- **JavaScript (ES6+)**: Vanilla JS for interactions
- **SCSS**: Modular stylesheet architecture (source files in `assets/scss/`)

### Libraries & Services

- **ScrollReveal.js**: Scroll animations
- **Font Awesome 6.5.0**: Icon library
- **Remix Icon 2.5.0**: Additional icon set
- **Google Fonts**: Poppins, Inter, Josefin Sans
- **EmailJS**: Contact form handling
- **Google Analytics**: Analytics tracking
- **Trustpilot**: Review widget

### External Services

- **Google OAuth**: Authentication
- **Microsoft Forms**: Registration forms
- **OneDrive**: Document hosting
- **YouTube**: Video embeds

## 📁 Project Structure

```
Ethioware/
├── index.html                 # Main homepage
├── privacy.html              # Privacy policy page
├── apply.html                # Application page
├── pay.html                  # Payment page
├── anteneh.html              # Team member profile
├── biniyam.html              # Team member profile
├── samuel.html               # Team member profile
├── .htaccess                 # Apache configuration
├── .hintrc                   # HTMLHint configuration
│
├── assets/
│   ├── css/
│   │   ├── styles.css        # Main stylesheet
│   │   └── bio.css           # Bio page styles
│   │
│   ├── js/
│   │   ├── main.js           # Main JavaScript (navigation, theme, etc.)
│   │   ├── script.js         # Partner logo animation
│   │   ├── bio.js            # Bio page functionality
│   │   ├── sendmail.js       # EmailJS integration
│   │   └── scrollreveal.min.js # Scroll animations
│   │
│   ├── img/                  # Images and assets
│   │   ├── logo.png
│   │   ├── plan.webp
│   │   └── [partner logos, certificates, etc.]
│   │
│   ├── scss/                 # SCSS source files (for development)
│   │   ├── base/
│   │   ├── components/
│   │   ├── layout/
│   │   ├── theme/
│   │   └── styles.scss
│   │
│   └── resumes/              # Resume PDFs
│
├── certificates/             # Certificate & interview pages (235 files)
│   └── [WI*/MB*/EB*/ES*/SEB*/MEB*/... .html]
│       # Served at short URLs like /WI10092516 via an internal
│       # rewrite in .htaccess — the public URL never changes.
│
├── pages/                    # Additional pages
│
├── cognify/                  # Authentication subdomain
│   ├── auth.html
│   ├── auth.js
│   └── auth.css
│
└── README.md                 # This file
```

## 🚀 Getting Started

### Prerequisites

- A modern web server (Apache, Nginx, or any static file server)
- No build process required (static HTML/CSS/JS)

### Installation

1. **Clone the repository**

   ```bash
   git clone <repository-url>
   cd Ethioware
   ```

2. **Upload to web server**

   - Upload all files to your web server root directory
   - Ensure `.htaccess` is properly configured (for Apache)
   - Verify file permissions are correct

3. **Configure external services** (optional)

   - Update Google Analytics ID in `index.html`
   - Configure EmailJS service ID in `assets/js/sendmail.js`
   - Update Trustpilot widget configuration
   - Configure Google OAuth credentials

4. **Access the website**
   - Open `index.html` in a browser or navigate to your domain

### Local Development

For local development without a server:

```bash
# Using Python's built-in server
python3 -m http.server 8000

# Using Node.js http-server
npx http-server

# Using PHP's built-in server
php -S localhost:8000
```

Then open `http://localhost:8000` in your browser.

## ⚡ Performance Optimizations

The website implements several performance optimizations:

### Loading Optimizations

- ✅ **Lazy Loading**: Images and iframes load only when needed
- ✅ **Resource Hints**: Preconnect and DNS-prefetch for external domains
- ✅ **Deferred Scripts**: Non-critical scripts load after page render
- ✅ **Font Optimization**: `font-display: swap` for Google Fonts
- ✅ **Async Loading**: External resources load asynchronously

### Rendering Optimizations

- ✅ **CSS Variables**: Consistent theming with CSS custom properties
- ✅ **GPU Acceleration**: Transform properties for smooth animations
- ✅ **Intersection Observer**: Stats animation triggers on scroll
- ✅ **RequestAnimationFrame**: Smooth partner logo animation

### Mobile Optimizations

- ✅ **Responsive Images**: Proper sizing and lazy loading
- ✅ **Touch Targets**: Minimum 44x44px for mobile usability
- ✅ **Reduced Motion**: Respects `prefers-reduced-motion`
- ✅ **Viewport Optimization**: Proper meta tags for mobile devices

### Code Optimizations

- ✅ **Minified Assets**: Compressed JavaScript and CSS
- ✅ **Consolidated CSS**: Single stylesheet for main styles
- ✅ **Efficient Selectors**: Optimized CSS selectors
- ✅ **Event Delegation**: Efficient event handling

## 🌐 Browser Support

| Browser       | Version           | Support |
| ------------- | ----------------- | ------- |
| Chrome        | Latest 2 versions | ✅ Full |
| Firefox       | Latest 2 versions | ✅ Full |
| Safari        | Latest 2 versions | ✅ Full |
| Edge          | Latest 2 versions | ✅ Full |
| Opera         | Latest 2 versions | ✅ Full |
| Mobile Safari | iOS 12+           | ✅ Full |
| Chrome Mobile | Latest            | ✅ Full |

## 📱 Responsive Design

The website is fully responsive with breakpoints at:

- **Mobile**: 320px - 767px
- **Tablet**: 768px - 991px
- **Desktop**: 992px - 1199px
- **Large Desktop**: 1200px+

### Mobile-First Approach

- Touch-friendly navigation menu
- Optimized font sizes (16px minimum to prevent zoom on iOS)
- Stacked layouts for better mobile viewing
- Reduced animation complexity on mobile devices

## 🎨 Design System

### Color Palette

- **Primary Blue**: `hsl(215, 80%, 32%)`
- **Background**: `hsl(215, 0%, 100%)` (Light) / `hsl(215, 8%, 12%)` (Dark)
- **Text**: `hsl(215, 4%, 15%)` (Light) / `hsl(215, 30%, 95%)` (Dark)

### Typography

- **Body Font**: Poppins
- **Heading Font**: Poppins (Semi-bold, Bold)
- **Font Sizes**: Responsive scale from 0.75rem to 3.5rem

### Border Radius

- **Small**: 0.5rem
- **Medium**: 1rem
- **Large**: 1.5rem
- **Round**: 90px (for circular elements)

### Glassmorphism

The website uses a glassmorphism design pattern:

- Backdrop blur: 10px
- Semi-transparent background
- Subtle borders with opacity

## 🤝 Contributing

We welcome contributions! Please follow these steps:

1. Fork the repository
2. Create a feature branch (`git checkout -b feature/AmazingFeature`)
3. Commit your changes (`git commit -m 'Add some AmazingFeature'`)
4. Push to the branch (`git push origin feature/AmazingFeature`)
5. Open a Pull Request

### Development Guidelines

- Follow existing code style and conventions
- Ensure responsive design works on all devices
- Test in multiple browsers
- Maintain accessibility standards
- Update documentation as needed

## 📞 Contact

### General Inquiries

- **Email**: info@ethioware.org
- **Parent Enrollment**: parents@ethioware.org
- **WhatsApp**: [Contact via WhatsApp](https://wa.me/message/W4GUO2DOV4HWI1)

### Address

22 St. Addis Ababa, Ethiopia

### Social Media

- **LinkedIn**: [@ethioware](https://www.linkedin.com/company/ethioware)
- **Twitter**: [@ethioware](https://twitter.com/ethioware)
- **YouTube**: [@ethioware](https://youtube.com/@ethioware)
- **Telegram**: [@ethioware](https://t.me/ethioware)
- **Instagram**: [@ethioware\_](https://instagram.com/ethioware_)
- **Facebook**: [@ethiowareEI](https://facebook.com/ethiowareEI)

## 📊 Statistics

- **170+** Graduates
- **30+** Mentors
- **3** Partnerships
- **8+** Nationalities

## 🔒 Privacy

For information about how we handle user data, please see our [Privacy Policy](privacy.html).

## 📄 License

© 2026 Ethioware EdTech Initiative. All Rights Reserved.

## 🙏 Acknowledgments

Special thanks to:

- All volunteer mentors and experts
- Partner organizations and institutions
- The Ethiopian educational community
- Open source contributors and libraries

---


For more information, visit [ethioware.org](https://ethioware.org)
