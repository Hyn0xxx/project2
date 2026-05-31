// Мобильное меню с Formcarry
document.addEventListener('DOMContentLoaded', function() {
    const mobileMenuBtn = document.getElementById('mobileMenuBtn');
    const mobileMenu = document.getElementById('mobileMenu');
    const closeMenuBtn = document.getElementById('closeMenuBtn');
    const mobileLinks = document.querySelectorAll('.mobile-nav a');
    const contactBtn = document.getElementById('contactBtn');
    const contactBtnMobile = document.getElementById('contactBtnMobile');

    // Открытие мобильного меню
    mobileMenuBtn.addEventListener('click', function() {
        mobileMenu.classList.add('active');
        document.body.style.overflow = 'hidden';
        updateURL('menu_open');
    });

    // Закрытие мобильного меню
    closeMenuBtn.addEventListener('click', function() {
        closeMobileMenu();
    });

    // Закрытие меню при клике на ссылку
    mobileLinks.forEach(link => {
        link.addEventListener('click', function() {
            closeMobileMenu();
        });
    });

    // Закрытие меню при клике вне его области
    document.addEventListener('click', function(e) {
        if (mobileMenu.classList.contains('active') && 
            !mobileMenu.contains(e.target) && 
            !mobileMenuBtn.contains(e.target)) {
            closeMobileMenu();
        }
    });

    // Закрытие меню при нажатии ESC
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape' && mobileMenu.classList.contains('active')) {
            closeMobileMenu();
        }
    });

    // Кнопка "Связь с нами" в десктопном меню
    contactBtn.addEventListener('click', function() {
        openContactModal();
        closeMobileMenu();
    });

    // Кнопка "Связь с нами" в мобильном меню
    contactBtnMobile.addEventListener('click', function() {
        openContactModal();
        closeMobileMenu();
    });

    // Функция закрытия мобильного меню
    function closeMobileMenu() {
        mobileMenu.classList.remove('active');
        document.body.style.overflow = '';
        updateURL('menu_closed');
    }

    // Функция для обновления URL
    function updateURL(state) {
        history.replaceState({ state: state }, '', window.location.pathname + window.location.hash);
    }

    // Функция для открытия модального окна
    function openContactModal() {
        const modalOverlay = document.getElementById('modalOverlay');
        const modal = document.getElementById('modal');
        
        // Создаем форму для модального окна
        const formHTML = `
            <form id="modalForm" class="contact-form">
                <div class="form-group">
                    <label for="modalName">Имя *</label>
                    <input type="text" id="modalName" name="name" required>
                </div>
                
                <div class="form-group">
                    <label for="modalPhone">Телефон *</label>
                    <input type="tel" id="modalPhone" name="phone" required>
                </div>
                
                <div class="form-group">
                    <label for="modalEmail">Email *</label>
                    <input type="email" id="modalEmail" name="email" required>
                </div>
                
                <div class="form-group">
                    <label for="modalCar">Интересующий автомобиль</label>
                    <select id="modalCar" name="car">
                        <option value="">Выберите модель</option>
                        <option value="porsche-panamera">Porsche Panamera</option>
                        <option value="mercedes-s-class">Mercedes-Benz S-Class</option>
                        <option value="bmw-7-series">BMW 7 Series</option>
                        <option value="audi-a8">Audi A8</option>
                        <option value="lexus-ls">Lexus LS</option>
                    </select>
                </div>
                
                <div class="form-group">
                    <label for="modalMessage">Сообщение</label>
                    <textarea id="modalMessage" name="message" placeholder="Ваши пожелания и вопросы..."></textarea>
                </div>
                
                <!-- Скрытое поле для Formcarry -->
                <input type="hidden" name="_subject" value="Заявка из модального окна AutoElite">
                <input type="text" name="_gotcha" style="display:none">
                
                <div class="form-checkbox">
                    <input type="checkbox" id="modalPrivacy" name="privacy" required>
                    <label for="modalPrivacy">Согласен на обработку персональных данных</label>
                </div>
                
                <button type="submit" class="btn-submit">
                    <span>Отправить заявку</span>
                    <div class="spinner hidden"></div>
                </button>
                
                <div class="form-message hidden" id="modalFormMessage"></div>
            </form>
        `;
        
        // Вставляем форму в модальное окно
        modal.innerHTML = `
            <button class="modal-close" id="modalClose">
                <i class="fas fa-times"></i>
            </button>
            <h2 class="modal-title">Связь с нами</h2>
            ${formHTML}
        `;
        
        // Показываем модальное окно
        modalOverlay.classList.remove('hidden');
        document.body.style.overflow = 'hidden';
        updateURL('modal_open');
        
        // Загружаем сохраненные данные
        loadFormDataFromLocalStorage();
        
        // Добавляем обработчик закрытия модального окна
        const modalClose = document.getElementById('modalClose');
        modalClose.addEventListener('click', closeModal);
        
        // Закрытие модального окна при клике вне его
        modalOverlay.addEventListener('click', function(e) {
            if (e.target === modalOverlay) {
                closeModal();
            }
        });
        
        // Закрытие по ESC
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape' && !modalOverlay.classList.contains('hidden')) {
                closeModal();
            }
        });
        
        // Обработка формы в модальном окне
        const modalForm = document.getElementById('modalForm');
        if (modalForm) {
            modalForm.addEventListener('submit', handleModalFormSubmit);
        }
    }

    // Функция закрытия модального окна
    function closeModal() {
        const modalOverlay = document.getElementById('modalOverlay');
        modalOverlay.classList.add('hidden');
        document.body.style.overflow = '';
        updateURL('modal_closed');
    }

    // Загрузка данных формы из localStorage
    function loadFormDataFromLocalStorage() {
        try {
            const savedForms = JSON.parse(localStorage.getItem('autoelite_forms')) || [];
            if (savedForms.length > 0) {
                const lastForm = savedForms[savedForms.length - 1];
                
                if (lastForm.name) document.getElementById('modalName').value = lastForm.name;
                if (lastForm.phone) document.getElementById('modalPhone').value = lastForm.phone;
                if (lastForm.email) document.getElementById('modalEmail').value = lastForm.email;
                if (lastForm.car) document.getElementById('modalCar').value = lastForm.car;
                if (lastForm.message) document.getElementById('modalMessage').value = lastForm.message;
                
                document.getElementById('modalPrivacy').checked = true;
            }
        } catch (error) {
            console.error('Ошибка загрузки данных формы:', error);
        }
    }

    // Обработка отправки формы в модальном окне
    async function handleModalFormSubmit(e) {
        e.preventDefault();
        
        const form = e.target;
        const submitBtn = form.querySelector('.btn-submit');
        const spinner = form.querySelector('.spinner');
        const messageDiv = document.getElementById('modalFormMessage');
        const submitText = submitBtn.querySelector('span');
        const originalText = submitText.textContent;
        
        // Блокируем кнопку
        submitBtn.disabled = true;
        spinner.classList.remove('hidden');
        submitText.textContent = 'Отправка...';
        messageDiv.classList.add('hidden');
        
        // Собираем данные
        const formData = new FormData(form);
        const data = Object.fromEntries(formData.entries());
        
        // Данные для Formcarry
        const formcarryData = {
            ...data,
            _subject: 'Заявка из модального окна AutoElite',
            _replyto: data.email || '',
            _gotcha: '',
            source: 'modal_form',
            timestamp: new Date().toLocaleString('ru-RU')
        };
        
        try {
            // Отправка на Formcarry
            const response = await fetch('https://formcarry.com/s/6hnv04gn1c2', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json'
                },
                body: JSON.stringify(formcarryData)
            });
            
            const result = await response.json();
            
            if (response.ok && result.code === 200) {
                // Успех
                showModalMessage('✅ Спасибо за заявку! Мы свяжемся с вами в течение 15 минут.', 'success');
                
                // Сохраняем в localStorage
                saveFormToLocalStorage(data);
                
                // Очищаем форму через 2 секунды
                setTimeout(() => {
                    form.reset();
                    closeModal();
                }, 2000);
            } else {
                throw new Error(result.message || 'Ошибка отправки');
            }
        } catch (error) {
            console.error('Ошибка отправки формы:', error);
            showModalMessage(`❌ Ошибка: ${error.message}. Пожалуйста, попробуйте еще раз.`, 'error');
        } finally {
            // Разблокируем кнопку
            submitBtn.disabled = false;
            spinner.classList.add('hidden');
            submitText.textContent = originalText;
        }
    }

    // Функция для показа сообщений в модальном окне
    function showModalMessage(text, type) {
        const messageDiv = document.getElementById('modalFormMessage');
        messageDiv.textContent = text;
        messageDiv.className = `form-message ${type}`;
        messageDiv.classList.remove('hidden');
    }

    // Функция для сохранения данных формы
    function saveFormToLocalStorage(data) {
        try {
            const savedForms = JSON.parse(localStorage.getItem('autoelite_forms')) || [];
            savedForms.push({
                ...data,
                timestamp: new Date().toISOString()
            });
            
            if (savedForms.length > 10) {
                savedForms.shift();
            }
            
            localStorage.setItem('autoelite_forms', JSON.stringify(savedForms));
        } catch (error) {
            console.error('Ошибка сохранения данных:', error);
        }
    }
});