// Обработчик формы с Formcarry
document.addEventListener('DOMContentLoaded', function() {
    const contactForm = document.getElementById('contactForm');
    const submitBtn = document.getElementById('submitBtn');
    const spinner = document.getElementById('spinner');
    const formMessage = document.getElementById('formMessage');

    // Ваш URL Formcarry
    const FORM_ENDPOINT = 'https://formcarry.com/s/6hnv04gn1c2';

    contactForm.addEventListener('submit', async function(e) {
        e.preventDefault();
        
        // Показываем спиннер и блокируем кнопку
        submitBtn.disabled = true;
        spinner.classList.remove('hidden');
        const submitText = submitBtn.querySelector('span');
        const originalText = submitText.textContent;
        submitText.textContent = 'Отправка...';
        
        // Собираем данные формы
        const formData = new FormData(contactForm);
        const data = Object.fromEntries(formData.entries());
        
        // Добавляем дополнительные данные для Formcarry
        const formcarryData = {
            ...data,
            _subject: 'Новая заявка с сайта AutoElite',
            _replyto: data.email || '',
            _gotcha: '', // Anti-spam поле
            source: 'main_form',
            timestamp: new Date().toLocaleString('ru-RU')
        };
        
        try {
            // Отправка данных на Formcarry
            const response = await fetch(FORM_ENDPOINT, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json'
                },
                body: JSON.stringify(formcarryData)
            });
            
            const result = await response.json();
            
            if (response.ok && result.code === 200) {
                // Успешная отправка
                showMessage('✅ Спасибо за заявку! Мы свяжемся с вами в течение 15 минут.', 'success');
                contactForm.reset();
                
                // Сохраняем данные в localStorage
                saveToLocalStorage(data);
                
                // Обновляем историю
                updateURL('form_success');
            } else {
                // Ошибка от Formcarry
                const errorMsg = result.message || 'Ошибка отправки формы';
                throw new Error(errorMsg);
            }
        } catch (error) {
            // Ошибка при отправке
            console.error('Ошибка отправки формы:', error);
            showMessage(`❌ Ошибка: ${error.message}. Пожалуйста, попробуйте еще раз.`, 'error');
        } finally {
            // Скрываем спиннер и разблокируем кнопку
            submitBtn.disabled = false;
            spinner.classList.add('hidden');
            submitText.textContent = originalText;
        }
    });

    // Функция для показа сообщений
    function showMessage(text, type) {
        formMessage.textContent = text;
        formMessage.className = `form-message ${type}`;
        formMessage.classList.remove('hidden');
        
        // Скрываем сообщение через 5 секунд
        setTimeout(() => {
            formMessage.classList.add('hidden');
        }, 5000);
    }

    // Функция для сохранения в localStorage
    function saveToLocalStorage(data) {
        try {
            const savedForms = JSON.parse(localStorage.getItem('autoelite_forms')) || [];
            savedForms.push({
                ...data,
                timestamp: new Date().toISOString()
            });
            
            // Сохраняем только последние 10 форм
            if (savedForms.length > 10) {
                savedForms.shift();
            }
            
            localStorage.setItem('autoelite_forms', JSON.stringify(savedForms));
            console.log('Данные сохранены в localStorage:', savedForms.length, 'форм');
        } catch (error) {
            console.error('Ошибка сохранения в localStorage:', error);
        }
    }

    // Загрузка сохраненных данных при загрузке страницы
    function loadFromLocalStorage() {
        try {
            const savedForms = JSON.parse(localStorage.getItem('autoelite_forms')) || [];
            if (savedForms.length > 0) {
                const lastForm = savedForms[savedForms.length - 1];
                
                // Заполняем поля формы последними данными
                if (lastForm.name) document.getElementById('name').value = lastForm.name;
                if (lastForm.phone) document.getElementById('phone').value = lastForm.phone;
                if (lastForm.email) document.getElementById('email').value = lastForm.email;
                if (lastForm.car) document.getElementById('car').value = lastForm.car;
                if (lastForm.message) document.getElementById('message').value = lastForm.message;
                
                // Автоматически отмечаем чекбокс
                document.getElementById('privacy').checked = true;
                
                console.log('Данные загружены из localStorage');
            }
        } catch (error) {
            console.error('Ошибка загрузки из localStorage:', error);
        }
    }

    // Работа с History API
    function updateURL(section) {
        const sectionName = section || window.location.hash.replace('#', '') || 'home';
        
        // Обновляем URL без перезагрузки страницы
        history.pushState({ section: sectionName }, '', `#${sectionName}`);
        
        // Сохраняем текущее состояние в localStorage
        localStorage.setItem('autoelite_current_section', sectionName);
    }

    // Обработка кнопок браузера "назад"/"вперед"
    window.addEventListener('popstate', function(event) {
        if (event.state && event.state.section) {
            handleSectionChange(event.state.section);
        }
    });

    // Функция для обработки смены раздела
    function handleSectionChange(sectionName) {
        const targetElement = document.querySelector(`#${sectionName}`);
        if (targetElement) {
            const headerHeight = document.querySelector('.navbar').offsetHeight;
            const targetPosition = targetElement.offsetTop - headerHeight - 20;
            
            window.scrollTo({
                top: targetPosition,
                behavior: 'smooth'
            });
        }
    }

    // Загрузка последнего раздела из localStorage
    function loadLastSection() {
        const lastSection = localStorage.getItem('autoelite_current_section') || 'home';
        if (lastSection && lastSection !== 'home') {
            setTimeout(() => {
                handleSectionChange(lastSection);
            }, 100);
        }
    }

    // Инициализация
    loadFromLocalStorage();
    loadLastSection();
    
    // Обновляем URL при загрузке страницы
    window.addEventListener('load', function() {
        updateURL();
    });
    
    // Обновляем URL при клике на якорные ссылки
    document.querySelectorAll('a[href^="#"]').forEach(link => {
        link.addEventListener('click', function() {
            const href = this.getAttribute('href');
            if (href && href !== '#') {
                setTimeout(() => {
                    updateURL(href.replace('#', ''));
                }, 500);
            }
        });
    });

    // Обновляем URL при прокрутке
    let scrollTimeout;
    window.addEventListener('scroll', function() {
        clearTimeout(scrollTimeout);
        scrollTimeout = setTimeout(() => {
            // Определяем текущий раздел по позиции прокрутки
            const sections = document.querySelectorAll('section[id], footer[id]');
            const scrollPosition = window.scrollY + 100;
            
            for (const section of sections) {
                const sectionTop = section.offsetTop;
                const sectionBottom = sectionTop + section.offsetHeight;
                
                if (scrollPosition >= sectionTop && scrollPosition < sectionBottom) {
                    updateURL(section.id);
                    break;
                }
            }
        }, 150);
    });
});